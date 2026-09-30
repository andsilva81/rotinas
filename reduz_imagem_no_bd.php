<?php
/**
 * Processa as fotos de um ou mais colaboradores (IDs separados por vírgula):
 *  1. Busca a imagem na tabela tbfoto (campo imagem) no SQL Server
 *  2. Corrige a orientação EXIF (fotos de celular), se houver
 *  3. Se a imagem estiver deitada (paisagem), gira para retrato
 *  4. Reduz para no máximo 800px de largura/altura
 *  5. Faz UPDATE na tabela com a nova imagem
 *
 * Requisitos: extensões pdo_sqlsrv e gd (exif é opcional, mas recomendada)
 */

// ================== CONFIGURAÇÃO ==================
$servidor   = 'localhost';        // ex.: 'SERVIDOR\\INSTANCIA' ou '192.168.0.10,1433'
$banco      = 'MeuBanco';
$usuario    = 'usuario';
$senha      = 'senha';

$tabela       = 'tbfoto';
$campoImagem  = 'imagem';
$campoId      = 'idcolaborador';
$tamanhoMax   = 800;              // pixels
$sentidoGiro  = 'horario';        // 'horario' ou 'antihorario' para imagens deitadas
$qualidadeJpg = 85;               // 0-100
// ==================================================

set_time_limit(0);

$erro = '';
$resultados = [];

/** Aplica a orientação gravada no EXIF (fotos de celular costumam vir "deitadas" só no pixel). */
function corrigirExif($img, string $dados)
{
    if (!function_exists('exif_read_data')) {
        return $img;
    }
    $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($dados));
    $orientacao = $exif['Orientation'] ?? 1;

    switch ($orientacao) {
        case 3: $img = imagerotate($img, 180, 0); break;
        case 6: $img = imagerotate($img, -90, 0); break;
        case 8: $img = imagerotate($img, 90, 0);  break;
    }
    return $img;
}

/**
 * Processa a imagem. Retorna array com os bytes finais e o que foi feito,
 * ou 'alterada' => false se não houve necessidade de mudança.
 */
function processarImagem(string $dados, int $max, string $sentido, int $qualidade): array
{
    $dim = @getimagesizefromstring($dados);
    if ($dim === false) {
        throw new Exception('Conteúdo não é uma imagem válida.');
    }
    $tipo = $dim[2];

    $img = @imagecreatefromstring($dados);
    if ($img === false) {
        throw new Exception('Formato de imagem não suportado pelo GD.');
    }

    $acoes = [];
    $larguraOrig = imagesx($img);
    $alturaOrig  = imagesy($img);

    // 1) Orientação EXIF (somente JPEG)
    if ($tipo === IMAGETYPE_JPEG) {
        $antes = [imagesx($img), imagesy($img)];
        $img = corrigirExif($img, $dados);
        if ($antes !== [imagesx($img), imagesy($img)]) {
            $acoes[] = 'orientação EXIF corrigida';
        }
    }

    // 2) Se estiver deitada (largura > altura), gira para retrato
    if (imagesx($img) > imagesy($img)) {
        $angulo = ($sentido === 'antihorario') ? 90 : -90; // GD gira no sentido anti-horário
        $transp = imagecolorallocatealpha($img, 0, 0, 0, 127);
        $img = imagerotate($img, $angulo, $transp);
        $acoes[] = 'girada para retrato';
    }

    // 3) Redimensiona (nunca amplia)
    $larg = imagesx($img);
    $alt  = imagesy($img);
    $escala = min($max / $larg, $max / $alt, 1);

    if ($escala < 1) {
        $novaLarg = max(1, (int) round($larg * $escala));
        $novaAlt  = max(1, (int) round($alt * $escala));

        $destino = imagecreatetruecolor($novaLarg, $novaAlt);
        if ($tipo === IMAGETYPE_PNG || $tipo === IMAGETYPE_GIF) {
            imagealphablending($destino, false);
            imagesavealpha($destino, true);
            imagefilledrectangle($destino, 0, 0, $novaLarg, $novaAlt,
                imagecolorallocatealpha($destino, 0, 0, 0, 127));
        }
        imagecopyresampled($destino, $img, 0, 0, 0, 0, $novaLarg, $novaAlt, $larg, $alt);
        imagedestroy($img);
        $img = $destino;
        $acoes[] = 'reduzida';
    }

    if (!$acoes) {
        imagedestroy($img);
        return ['alterada' => false, 'dim' => "{$larguraOrig}x{$alturaOrig}"];
    }

    // 4) Gera os bytes no mesmo formato da original
    ob_start();
    if ($tipo === IMAGETYPE_PNG) {
        imagesavealpha($img, true);
        imagepng($img, null, 6);
        $mime = 'image/png';
    } elseif ($tipo === IMAGETYPE_GIF) {
        imagegif($img);
        $mime = 'image/gif';
    } else {
        imagejpeg($img, null, $qualidade);
        $mime = 'image/jpeg';
    }
    $bytes = ob_get_clean();

    $dimFinal = imagesx($img) . 'x' . imagesy($img);
    imagedestroy($img);

    return [
        'alterada' => true,
        'bytes'    => $bytes,
        'mime'     => $mime,
        'acoes'    => $acoes,
        'dim'      => "{$larguraOrig}x{$alturaOrig} → {$dimFinal}",
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Separa os IDs por vírgula, remove espaços, vazios e duplicados
    $ids = array_unique(array_filter(array_map('trim', explode(',', $_POST['ids'] ?? '')), 'strlen'));
    $invalidos = array_filter($ids, fn($i) => !ctype_digit($i));

    if (!$ids) {
        $erro = 'Informe ao menos um ID.';
    } elseif ($invalidos) {
        $erro = 'IDs inválidos: ' . implode(', ', $invalidos);
    } else {
        try {
            $pdo = new PDO(
                "sqlsrv:Server=$servidor;Database=$banco;TrustServerCertificate=1",
                $usuario,
                $senha,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            $sel = $pdo->prepare("SELECT TOP 1 $campoImagem FROM $tabela WHERE $campoId = ?");
            $upd = $pdo->prepare("UPDATE $tabela SET $campoImagem = ? WHERE $campoId = ?");

            foreach ($ids as $id) {
                $linha = ['id' => $id, 'status' => '', 'detalhe' => '', 'thumb' => '', 'ok' => false];
                try {
                    $sel->execute([(int) $id]);
                    $sel->bindColumn(1, $dados, PDO::PARAM_LOB, 0, PDO::SQLSRV_ENCODING_BINARY);
                    $achou = $sel->fetch(PDO::FETCH_BOUND);
                    $sel->closeCursor();

                    if (!$achou || $dados === null) {
                        $linha['status'] = 'Não encontrada';
                    } else {
                        if (is_resource($dados)) {
                            $dados = stream_get_contents($dados);
                        }

                        $r = processarImagem($dados, $tamanhoMax, $sentidoGiro, $qualidadeJpg);

                        if (!$r['alterada']) {
                            $linha['status']  = 'Sem alteração';
                            $linha['detalhe'] = "Já está em retrato e com até {$tamanhoMax}px ({$r['dim']})";
                            $linha['ok'] = true;
                        } else {
                            $bytes = $r['bytes'];
                            $upd->bindParam(1, $bytes, PDO::PARAM_LOB, 0, PDO::SQLSRV_ENCODING_BINARY);
                            $upd->bindValue(2, (int) $id, PDO::PARAM_INT);
                            $upd->execute();

                            $linha['status']  = 'Atualizada';
                            $linha['detalhe'] = ucfirst(implode(', ', $r['acoes'])) . " ({$r['dim']})";
                            $linha['thumb']   = "data:{$r['mime']};base64," . base64_encode($bytes);
                            $linha['ok'] = true;
                        }
                    }
                } catch (Exception $e) {
                    $linha['status']  = 'Erro';
                    $linha['detalhe'] = $e->getMessage();
                }
                $resultados[] = $linha;
                unset($dados);
            }
        } catch (PDOException $e) {
            $erro = 'Erro ao conectar no banco: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Ajuste de fotos dos colaboradores</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 1000px; margin: 40px auto; padding: 0 16px; }
        form { display: flex; gap: 8px; margin-bottom: 20px; }
        input[type=text] { padding: 8px; font-size: 16px; flex: 1; }
        button { padding: 8px 16px; font-size: 16px; cursor: pointer; }
        .erro { color: #b00020; background: #fde7ea; padding: 10px; border-radius: 4px; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; vertical-align: middle; }
        th { background: #f4f4f4; }
        .ok { color: #1b5e20; font-weight: bold; }
        .falha { color: #b00020; font-weight: bold; }
        td img { max-height: 120px; border: 1px solid #ccc; }
    </style>
</head>
<body>
    <h2>Ajustar fotos dos colaboradores</h2>
    <p>Informe um ou mais IDs separados por vírgula. Cada foto deitada será girada para retrato,
       reduzida para no máximo <?= $tamanhoMax ?>px e gravada no banco.</p>

    <form method="post">
        <input type="text" name="ids" placeholder="Ex.: 101, 102, 205"
               value="<?= htmlspecialchars($_POST['ids'] ?? '') ?>" required>
        <button type="submit"
                onclick="return confirm('As imagens originais serão substituídas no banco. Continuar?');">
            Processar e salvar
        </button>
    </form>

    <?php if ($erro): ?>
        <div class="erro"><?= htmlspecialchars($erro) ?></div>
    <?php endif; ?>

    <?php if ($resultados): ?>
        <table>
            <tr><th>ID</th><th>Status</th><th>Detalhe</th><th>Nova imagem</th></tr>
            <?php foreach ($resultados as $l): ?>
                <tr>
                    <td><?= htmlspecialchars($l['id']) ?></td>
                    <td class="<?= $l['ok'] ? 'ok' : 'falha' ?>"><?= htmlspecialchars($l['status']) ?></td>
                    <td><?= htmlspecialchars($l['detalhe']) ?></td>
                    <td><?php if ($l['thumb']): ?><img src="<?= $l['thumb'] ?>" alt=""><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
</body>
</html>
