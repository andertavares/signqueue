<?php
session_start();
date_default_timezone_set('America/Sao_Paulo');

// --- CONFIGURAÇÕES ---
$senha_admin = 'mudar123'; // <<< ALTERE PARA A SUA SENHA DE ADMIN AQUI
$db_file = __DIR__ . '/database.sqlite';
$lock_timeout = 20 * 60;

// --- LÓGICA DE LOGIN / LOGOUT ---
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    unset($_SESSION['admin_logged']);
    header("Location: admin.php"); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['senha_login'])) {
    if ($_POST['senha_login'] === $senha_admin) {
        $_SESSION['admin_logged'] = true;
        header("Location: admin.php"); exit;
    } else {
        sleep(2); $erro_login = "Senha incorreta.";
    }
}
if (empty($_SESSION['admin_logged'])) {
?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Login - Admin</title>
        <style>body { font-family: system-ui, sans-serif; max-width: 400px; margin: 80px auto; padding: 20px; } .card { border: 1px solid #ccc; padding: 20px; border-radius: 8px; background: #f9f9f9; text-align: center; } input { width: 100%; padding: 10px; margin: 15px 0; box-sizing: border-box; } .btn { width: 100%; padding: 10px; background: #343a40; color: white; border: none; border-radius: 4px; cursor: pointer; } .erro { color: #721c24; background: #f8d7da; padding: 10px; border-radius: 4px; margin-bottom: 15px; }</style>
    </head>
    <body><div class="card"><h2>Acesso Restrito</h2><?php if (isset($erro_login)) echo "<div class='erro'>$erro_login</div>"; ?><form method="post"><input type="password" name="senha_login" required placeholder="Senha administrativa" autofocus><button type="submit" class="btn">Entrar</button></form></div></body>
    </html>
<?php exit; }

// --- ÁREA RESTRITA ---
if (!file_exists($db_file)) die("O banco de dados ainda não foi criado. Acesse o index.php e faça o upload do primeiro arquivo.");

$pdo = new PDO('sqlite:' . $db_file);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$mensagem = null;

// Garante que a tabela de versões exista
$pdo->exec("CREATE TABLE IF NOT EXISTS document_versions (
    id INTEGER PRIMARY KEY AUTOINCREMENT, doc_id TEXT, usuario TEXT, 
    file_name TEXT, mime_type TEXT, file_data BLOB, created_at INTEGER, FOREIGN KEY(doc_id) REFERENCES documents(id)
)");

// SINCRONIZAÇÃO RETROATIVA: Pega documentos antigos que não estão na tabela de versões e cria a versão inicial deles
$stmt_sync = $pdo->query("SELECT id, original_name, mime_type, file_data FROM documents WHERE id NOT IN (SELECT doc_id FROM document_versions)");
$docs_to_sync = $stmt_sync->fetchAll(PDO::FETCH_ASSOC);
foreach ($docs_to_sync as $d) {
    $stmt_ver = $pdo->prepare("INSERT INTO document_versions (doc_id, usuario, file_name, mime_type, file_data, created_at) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt_ver->execute([$d['id'], 'Sistema (Sincronização de Arquivo Antigo)', $d['original_name'], $d['mime_type'], $d['file_data'], time()]);
}

// Ação: Download do Documento Atual (atalho da listagem geral)
if (isset($_GET['action']) && $_GET['action'] === 'download_admin' && !empty($_GET['id'])) {
    $stmt = $pdo->prepare("SELECT file_data, original_name, mime_type FROM documents WHERE id = ?");
    $stmt->execute([$_GET['id']]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($doc && $doc['file_data']) {
        header('Content-Type: ' . ($doc['mime_type'] ?: 'application/pdf'));
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="admin_' . basename($doc['original_name']) . '"');
        echo $doc['file_data']; exit;
    }
    die("Arquivo não encontrado.");
}

// Ação: Download de uma Versão Histórica Específica (da tela de histórico)
if (isset($_GET['action']) && $_GET['action'] === 'download_version' && !empty($_GET['vid'])) {
    $stmt = $pdo->prepare("SELECT file_data, file_name, mime_type FROM document_versions WHERE id = ?");
    $stmt->execute([$_GET['vid']]);
    $version = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($version && $version['file_data']) {
        header('Content-Type: ' . ($version['mime_type'] ?: 'application/pdf'));
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="v' . $_GET['vid'] . '_' . basename($version['file_name']) . '"');
        echo $version['file_data']; exit;
    }
    die("Versão não encontrada.");
}

// Ação: Forçar Liberação
if (isset($_POST['action']) && $_POST['action'] === 'force_unlock' && !empty($_POST['doc_id'])) {
    $stmt = $pdo->prepare("UPDATE documents SET locked_by = NULL, locked_at = NULL, lock_pin = NULL WHERE id = ?");
    $stmt->execute([$_POST['doc_id']]);
    $mensagem = "Documento destrancado com sucesso!";
}

// Ação: Restaurar Versão Antiga
if (isset($_POST['action']) && $_POST['action'] === 'restore_version' && !empty($_POST['doc_id']) && !empty($_POST['vid'])) {
    $doc_id = $_POST['doc_id']; $vid = $_POST['vid'];
    
    // Pega a versão desejada
    $stmt = $pdo->prepare("SELECT file_data, file_name, mime_type FROM document_versions WHERE id = ?");
    $stmt->execute([$vid]);
    $version = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($version) {
        // Atualiza a tabela principal com o BLOB da versão antiga e destranca
        $update = $pdo->prepare("UPDATE documents SET file_data = ?, original_name = ?, mime_type = ?, locked_by = NULL, locked_at = NULL, lock_pin = NULL WHERE id = ?");
        $update->execute([$version['file_data'], $version['file_name'], $version['mime_type'], $doc_id]);
        
        // Registra o reparo no log público do index.php
        $log = $pdo->prepare("INSERT INTO signature_log (doc_id, usuario, signed_at) VALUES (?, ?, ?)");
        $log->execute([$doc_id, 'Administrador (Restaurou Backup Anterior)', time()]);
        
        // ADIÇÃO IMPORTANTE: Registra essa restauração como uma nova versão no histórico
        $stmt_ver = $pdo->prepare("INSERT INTO document_versions (doc_id, usuario, file_name, mime_type, file_data, created_at) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt_ver->execute([$doc_id, 'Administrador (Restaurou a versão v'.$vid.')', $version['file_name'], $version['mime_type'], $version['file_data'], time()]);

        $mensagem = "Arquivo substituído! O documento voltou para a versão restaurada, virou a versão atual e a fila foi destrancada.";
    }
}

// Ação: Excluir Documento Definitivamente
if (isset($_POST['action']) && $_POST['action'] === 'delete_doc' && !empty($_POST['doc_id'])) {
    $doc_id = $_POST['doc_id'];
    $pdo->prepare("DELETE FROM document_versions WHERE doc_id = ?")->execute([$doc_id]);
    $pdo->prepare("DELETE FROM signature_log WHERE doc_id = ?")->execute([$doc_id]);
    $pdo->prepare("DELETE FROM documents WHERE id = ?")->execute([$doc_id]);
    $pdo->exec("VACUUM");
    $mensagem = "Documento e histórico totalmente excluídos!";
}

// Verifica qual tela mostrar
$view = $_GET['view'] ?? 'list';
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel Admin - Fila de Assinaturas</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 900px; margin: 40px auto; padding: 20px; line-height: 1.6; }
        .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #eee; padding-bottom: 10px; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; vertical-align: middle; }
        th { background-color: #f8f9fa; }
        .status-livre { color: #28a745; font-weight: bold; }
        .status-ocupado { color: #dc3545; font-weight: bold; }
        .btn-sm { padding: 6px 12px; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 0.85em; text-decoration: none; display: inline-block; font-family: inherit; }
        .btn-info { background: #17a2b8; } .btn-info:hover { background: #138496; }
        .btn-primary { background: #007bff; } .btn-primary:hover { background: #0056b3; }
        .btn-warning { background: #ffc107; color: #212529; }
        .btn-danger { background: #dc3545; }
        .alerta { background: #d4edda; color: #155724; padding: 10px; border-radius: 4px; margin-bottom: 20px; }
        .actions-cell { display: flex; gap: 8px; flex-wrap: wrap; }
    </style>
</head>
<body>

    <div class="header">
        <h2>Gestão de Documentos</h2>
        <a href="?action=logout" style="color: #dc3545; font-weight: bold; text-decoration: none;">Sair do Painel</a>
    </div>

    <?php if ($mensagem): ?><div class="alerta"><?= htmlspecialchars($mensagem) ?></div><?php endif; ?>

    <!-- TELA 1: LISTAGEM GERAL -->
    <?php if ($view === 'list'): ?>
        <?php
        $documentos = $pdo->query("SELECT id, original_name, locked_by, locked_at FROM documents ORDER BY rowid DESC")->fetchAll(PDO::FETCH_ASSOC);
        if (count($documentos) === 0): ?>
            <p>Nenhum documento na fila no momento.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr><th>Arquivo</th><th>Status Atual</th><th>Ações Administrativas</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($documentos as $doc): ?>
                        <?php
                            $is_locked = false; $status_html = "<span class='status-livre'>Livre</span>";
                            if ($doc['locked_by']) {
                                $is_locked = true;
                                $status_html = "<span class='status-ocupado'>Trancado</span><br><small>Por: " . htmlspecialchars($doc['locked_by']) . "</small>";
                            }
                        ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($doc['original_name'], ENT_QUOTES, 'UTF-8') ?></strong><br>
                                <small><a href="index.php?id=<?= $doc['id'] ?>" target="_blank" style="color: #007bff;">Abrir página pública ↗</a></small>
                            </td>
                            <td><?= $status_html ?></td>
                            <td class="actions-cell">
                                <a href="?view=history&id=<?= $doc['id'] ?>" class="btn-sm btn-primary">Histórico & Restauração</a>
                                <a href="?action=download_admin&id=<?= $doc['id'] ?>" class="btn-sm btn-info">Baixar Atual</a>
                                
                                <?php if ($is_locked): ?>
                                    <form method="post" onsubmit="return confirm('Forçar liberação?');" style="margin:0;">
                                        <input type="hidden" name="action" value="force_unlock">
                                        <input type="hidden" name="doc_id" value="<?= $doc['id'] ?>">
                                        <button type="submit" class="btn-sm btn-warning">Destrancar</button>
                                    </form>
                                <?php endif; ?>
                                
                                <form method="post" onsubmit="return confirm('ATENÇÃO: Excluir arquivo e histórico definitivamente?');" style="margin:0;">
                                    <input type="hidden" name="action" value="delete_doc">
                                    <input type="hidden" name="doc_id" value="<?= $doc['id'] ?>">
                                    <button type="submit" class="btn-sm btn-danger">Excluir Tudo</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

    <!-- TELA 2: HISTÓRICO E RESTAURAÇÃO DE UM ARQUIVO -->
    <?php elseif ($view === 'history' && !empty($_GET['id'])): ?>
        <?php
        $doc_id = $_GET['id'];
        $stmt_vers = $pdo->prepare("SELECT id, usuario, file_name, created_at FROM document_versions WHERE doc_id = ? ORDER BY created_at ASC");
        $stmt_vers->execute([$doc_id]);
        $versoes = $stmt_vers->fetchAll(PDO::FETCH_ASSOC);
        ?>
        
        <div style="margin-bottom: 20px;">
            <a href="admin.php" style="text-decoration: none; color: #007bff; font-weight: bold;">← Voltar para a lista principal</a>
        </div>
        
        <h3>Backup de Versões do Documento</h3>
        <p style="font-size: 0.9em; color: #555;">Se a última pessoa corrompeu as assinaturas, faça o download das versões antigas para verificar qual está correta e clique em <b>Restaurar</b> para desfazer o erro.</p>
        
        <?php if (count($versoes) === 0): ?>
            <p>Nenhuma versão foi encontrada no sistema (algo deu errado na sincronização).</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr><th>Data/Hora</th><th>Ação / Usuário</th><th>Ações de Recuperação</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($versoes as $index => $ver): ?>
                        <tr style="<?= ($index === count($versoes) - 1) ? 'background-color: #f1f8ff;' : '' ?>">
                            <td><?= date('d/m/Y H:i', $ver['created_at']) ?></td>
                            <td>
                                <strong><?= htmlspecialchars($ver['usuario'], ENT_QUOTES, 'UTF-8') ?></strong><br>
                                <small>Arquivo: <?= htmlspecialchars($ver['file_name'], ENT_QUOTES, 'UTF-8') ?></small>
                                <?php if ($index === count($versoes) - 1) echo "<br><span style='color: #0056b3; font-size: 0.85em; font-weight: bold;'>(Estado Atual do Sistema)</span>"; ?>
                            </td>
                            <td class="actions-cell">
                                <a href="?action=download_version&vid=<?= $ver['id'] ?>" class="btn-sm btn-info" target="_blank">Baixar PDF</a>
                                
                                <?php if ($index !== count($versoes) - 1): ?>
                                    <form method="post" onsubmit="return confirm('Deseja realmente voltar o sistema para esta versão do arquivo? O arquivo atual será sobrescrito, mas essa ação ficará registrada no final da lista.');" style="margin:0;">
                                        <input type="hidden" name="action" value="restore_version">
                                        <input type="hidden" name="doc_id" value="<?= $doc_id ?>">
                                        <input type="hidden" name="vid" value="<?= $ver['id'] ?>">
                                        <button type="submit" class="btn-sm btn-warning">Restaurar para cá</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

    <?php endif; ?>

</body>
</html>