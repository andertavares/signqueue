<?php
session_start();
date_default_timezone_set('America/Sao_Paulo');

// --- CONFIGURAÇÕES ---
$db_file = __DIR__ . '/database.sqlite';
$lock_timeout = 20 * 60; // 20 minutos

// Configurações de Segurança
$senha_criacao = 'mudar123'; // <<< ALTERE PARA A SUA SENHA AQUI
$max_file_size = 15 * 1024 * 1024; // 15 MB em bytes

// Conexão com SQLite
$pdo = new PDO('sqlite:' . $db_file);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Criação das tabelas
$pdo->exec("CREATE TABLE IF NOT EXISTS documents (
    id TEXT PRIMARY KEY, original_name TEXT, mime_type TEXT, 
    file_data BLOB, locked_by TEXT, locked_at INTEGER
)");

$columns = $pdo->query("PRAGMA table_info(documents)")->fetchAll(PDO::FETCH_COLUMN, 1);
if (!in_array('lock_pin', $columns)) {
    $pdo->exec("ALTER TABLE documents ADD COLUMN lock_pin TEXT");
}

$pdo->exec("CREATE TABLE IF NOT EXISTS signature_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT, doc_id TEXT, 
    usuario TEXT, signed_at INTEGER, FOREIGN KEY(doc_id) REFERENCES documents(id)
)");

// NOVA TABELA: Histórico de arquivos (versões completas)
$pdo->exec("CREATE TABLE IF NOT EXISTS document_versions (
    id INTEGER PRIMARY KEY AUTOINCREMENT, doc_id TEXT, usuario TEXT, 
    file_name TEXT, mime_type TEXT, file_data BLOB, created_at INTEGER,
    FOREIGN KEY(doc_id) REFERENCES documents(id)
)");

// --- FUNÇÕES DE SEGURANÇA ---
function is_valid_pdf($tmp_file_path) {
    if (!file_exists($tmp_file_path)) return false;
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $tmp_file_path);
        finfo_close($finfo);
    } else {
        $mime = mime_content_type($tmp_file_path);
    }
    return $mime === 'application/pdf';
}

// --- ROTAS E AÇÕES ---
$action = $_GET['action'] ?? 'home';
$doc_id = $_GET['id'] ?? null;
$erro = null;
$mensagem_sucesso = null;

// Download do arquivo
if ($action === 'download' && $doc_id) {
    $stmt = $pdo->prepare("SELECT file_data, original_name FROM documents WHERE id = ?");
    $stmt->execute([$doc_id]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($doc && $doc['file_data']) {
        header('Content-Type: application/pdf');
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="' . basename($doc['original_name']) . '"');
        echo $doc['file_data'];
        exit;
    }
    die("Arquivo não encontrado.");
}

// Upload Inicial
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'upload_initial') {
    if (($_POST['senha'] ?? '') !== $senha_criacao) {
        sleep(2); $erro = "Senha incorreta.";
    } elseif (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
        $erro = "Erro no envio do arquivo.";
    } else {
        $file = $_FILES['document'];
        if (!is_uploaded_file($file['tmp_name'])) {
            $erro = "Upload inválido.";
        } elseif ($file['size'] > $max_file_size) {
            $erro = "O arquivo excede o limite máximo de 15 MB.";
        } elseif (!is_valid_pdf($file['tmp_name'])) {
            $erro = "Apenas PDFs reais são aceitos.";
        } else {
            $id = bin2hex(random_bytes(8));
            $file_data = file_get_contents($file['tmp_name']);
            $safe_filename = preg_replace('/[^a-zA-Z0-9_.-]/', '_', basename($file['name']));
            
            $stmt = $pdo->prepare("INSERT INTO documents (id, original_name, mime_type, file_data) VALUES (?, ?, 'application/pdf', ?)");
            $stmt->execute([$id, $safe_filename, $file_data]);
            
            // Salva a versão original no histórico
            $stmt_ver = $pdo->prepare("INSERT INTO document_versions (doc_id, usuario, file_name, mime_type, file_data, created_at) VALUES (?, ?, ?, 'application/pdf', ?, ?)");
            $stmt_ver->execute([$id, 'Administrador (Upload Original)', $safe_filename, $file_data, time()]);
            
            header("Location: ?id=$id");
            exit;
        }
    }
}

// Pegar o Lock
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'lock' && $doc_id) {
    $usuario = substr(trim(htmlspecialchars($_POST['usuario'] ?? '', ENT_QUOTES, 'UTF-8')), 0, 100);
    if (empty($usuario)) {
        $erro = "O nome é obrigatório.";
    } else {
        $stmt = $pdo->prepare("SELECT locked_by, locked_at FROM documents WHERE id = ?");
        $stmt->execute([$doc_id]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($doc) {
            $now = time();
            $can_lock = (!$doc['locked_by'] || ($now - $doc['locked_at'] > $lock_timeout));
            if ($can_lock) {
                $pin = str_pad(mt_rand(0, 999999), 6, '0', STR_PAD_LEFT); 
                $stmt = $pdo->prepare("UPDATE documents SET locked_by = ?, locked_at = ?, lock_pin = ? WHERE id = ?");
                $stmt->execute([$usuario, $now, $pin, $doc_id]);
                $_SESSION['my_name'] = $usuario; 
                header("Location: ?id=$doc_id&download_now=1"); 
                exit;
            } else {
                $erro = "Documento em uso. Tente novamente em breve.";
            }
        }
    }
}

// Assumir PIN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'assume_pin' && $doc_id) {
    $pin_digitado = trim($_POST['pin'] ?? '');
    $stmt = $pdo->prepare("SELECT locked_by, lock_pin FROM documents WHERE id = ?");
    $stmt->execute([$doc_id]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($doc && $doc['lock_pin'] !== null && $doc['lock_pin'] === $pin_digitado) {
        $_SESSION['my_name'] = $doc['locked_by'];
        header("Location: ?id=$doc_id"); exit;
    } else {
        sleep(1); $erro = "PIN incorreto.";
    }
}

// Devolver Arquivo Assinado
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'upload_signed' && $doc_id) {
    $stmt = $pdo->prepare("SELECT locked_by FROM documents WHERE id = ?");
    $stmt->execute([$doc_id]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$doc || $doc['locked_by'] !== $_SESSION['my_name']) {
        $erro = "Você não tem permissão ou seu tempo expirou.";
    } elseif (!isset($_FILES['signed_document']) || $_FILES['signed_document']['error'] !== UPLOAD_ERR_OK) {
        $erro = "Erro no envio do arquivo.";
    } else {
        $file = $_FILES['signed_document'];
        if (!is_uploaded_file($file['tmp_name']) || !is_valid_pdf($file['tmp_name'])) {
            $erro = "Arquivo inválido.";
        } else {
            $file_data = file_get_contents($file['tmp_name']);
            $safe_filename = preg_replace('/[^a-zA-Z0-9_.-]/', '_', basename($file['name']));
            
            $stmt = $pdo->prepare("UPDATE documents SET file_data = ?, original_name = ?, locked_by = NULL, locked_at = NULL, lock_pin = NULL WHERE id = ?");
            $stmt->execute([$file_data, $safe_filename, $doc_id]);
            
            $stmt_log = $pdo->prepare("INSERT INTO signature_log (doc_id, usuario, signed_at) VALUES (?, ?, ?)");
            $stmt_log->execute([$doc_id, $_SESSION['my_name'], time()]);
            
            // Salva o backup desta versão no histórico
            $stmt_ver = $pdo->prepare("INSERT INTO document_versions (doc_id, usuario, file_name, mime_type, file_data, created_at) VALUES (?, ?, ?, 'application/pdf', ?, ?)");
            $stmt_ver->execute([$doc_id, $_SESSION['my_name'], $safe_filename, $file_data, time()]);
            
            $mensagem_sucesso = "Documento assinado e liberado para o próximo!";
        }
    }
}

// Cancelar Lock
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'cancel_lock' && $doc_id) {
    $stmt = $pdo->prepare("SELECT locked_by FROM documents WHERE id = ?");
    $stmt->execute([$doc_id]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($doc && $doc['locked_by'] === $_SESSION['my_name']) {
        $stmt = $pdo->prepare("UPDATE documents SET locked_by = NULL, locked_at = NULL, lock_pin = NULL WHERE id = ?");
        $stmt->execute([$doc_id]);
        $mensagem_sucesso = "Você liberou o documento na fila sem realizar alterações.";
    }
}

// --- VIEWS (HTML) ---
$doc_info = null;
if ($doc_id) {
    $stmt = $pdo->prepare("SELECT id, original_name, locked_by, locked_at, lock_pin FROM documents WHERE id = ?");
    $stmt->execute([$doc_id]);
    $doc_info = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fila de Assinaturas</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 600px; margin: 40px auto; padding: 20px; line-height: 1.6; }
        .card { border: 1px solid #ccc; padding: 20px; border-radius: 8px; background: #f9f9f9; }
        .status-livre { color: green; font-weight: bold; }
        .status-ocupado { color: red; font-weight: bold; }
        .btn { padding: 10px 15px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; display: inline-block; font-size: 16px; }
        .btn:hover { background: #0056b3; }
        .btn-danger { background: #dc3545; }
        input[type="text"], input[type="password"], input[type="file"] { width: 100%; padding: 8px; margin-bottom: 15px; box-sizing: border-box; }
        .alerta { background: #d4edda; color: #155724; padding: 10px; border-radius: 4px; margin-bottom: 15px; }
        .erro { background: #f8d7da; color: #721c24; padding: 10px; border-radius: 4px; margin-bottom: 15px; }
        .govbr-link { display: inline-block; background: #1351b4; color: white; padding: 8px 12px; border-radius: 4px; text-decoration: none; font-weight: bold; margin: 15px 0; }
        .govbr-link:hover { background: #0c3882; }
        .aviso { background: #fff3cd; color: #856404; padding: 10px; border-radius: 4px; margin-bottom: 15px; }
        .pin-box { background: #e2e3e5; padding: 15px; border-radius: 6px; text-align: center; margin-bottom: 20px; border: 1px dashed #adb5bd; }
        .pin-code { font-size: 24px; font-weight: bold; letter-spacing: 5px; color: #495057; margin: 5px 0 0 0; }
        .cancel-box { border-top: 1px solid #ddd; margin-top: 25px; padding-top: 15px; text-align: center; }
    </style>
</head>
<body>

<?php if (isset($_GET['download_now']) && $_GET['download_now'] == '1' && $doc_info): ?>
    <script>
        window.onload = function() {
            window.location.href = "?action=download&id=<?= $doc_info['id'] ?>";
            window.history.replaceState({}, document.title, "?id=<?= $doc_info['id'] ?>");
        };
    </script>
<?php endif; ?>

<?php if (isset($erro)): ?><div class="erro"><?= htmlspecialchars($erro) ?></div><?php endif; ?>

<?php if (!$doc_id): ?>
    <div class="card">
        <h2>Criar Fila de Assinatura</h2>
        <form method="post" action="?action=upload_initial" enctype="multipart/form-data">
            <label>Selecione o documento PDF original (Máx 15MB):</label><br><br>
            <input type="file" name="document" required accept="application/pdf"><br>
            <label>Senha de criação:</label>
            <input type="password" name="senha" required placeholder="Digite a senha de administrador">
            <button type="submit" class="btn">Gerar Link de Compartilhamento</button>
        </form>
    </div>

<?php elseif ($doc_info): ?>
    <h2>Documento: <?= htmlspecialchars($doc_info['original_name'], ENT_QUOTES, 'UTF-8') ?></h2>
    <?php if (isset($mensagem_sucesso)): ?><div class="alerta"><?= $mensagem_sucesso ?></div><?php endif; ?>

    <div class="card">
        <?php
        $now = time();
        $is_locked = false; $is_locked_by_me = false; $can_override = false;
        $safe_locked_by = htmlspecialchars($doc_info['locked_by'] ?? '', ENT_QUOTES, 'UTF-8');

        if ($doc_info['locked_by']) {
            $elapsed = $now - $doc_info['locked_at'];
            if ($elapsed > $lock_timeout) {
                $can_override = true;
            } else {
                $is_locked = true;
                $is_locked_by_me = (isset($_SESSION['my_name']) && $_SESSION['my_name'] === $doc_info['locked_by']);
                $minutos_restantes = ceil(($lock_timeout - $elapsed) / 60);
            }
        }

        if ($is_locked_by_me): ?>
            <p class="status-ocupado">Você está com a posse do documento.</p>
            <?php if ($doc_info['lock_pin']): ?>
            <div class="pin-box">
                <p style="margin: 0; font-size: 0.9em; color: #666;">Vai assinar em outro computador ou celular? <br>Abra este link lá e informe o PIN abaixo:</p>
                <h3 class="pin-code"><?= htmlspecialchars($doc_info['lock_pin'], ENT_QUOTES, 'UTF-8') ?></h3>
            </div>
            <?php endif; ?>

            <p><strong>1. Assine o documento:</strong></p>
            <a href="https://assinador.iti.br/assinatura/index.xhtml" target="_blank" rel="noopener noreferrer" class="govbr-link">Ir para o Assinador gov.br</a>
            <p style="font-size: 0.9em; color: #555;">(Use o arquivo baixado automaticamente no seu computador)</p>
            <hr style="margin: 20px 0; border: 0; border-top: 1px solid #ddd;">
            <p><strong>2. Devolva o arquivo assinado:</strong></p>
            <form method="post" action="?action=upload_signed&id=<?= $doc_info['id'] ?>" enctype="multipart/form-data">
                <input type="file" name="signed_document" required accept="application/pdf"><br>
                <button type="submit" class="btn" style="background: #28a745; width: 100%;">Devolver Arquivo Assinado</button>
            </form>
            <p style="margin-top: 15px; font-size: 0.9em; text-align: center;"><a href="?action=download&id=<?= $doc_info['id'] ?>">Baixar arquivo reservado novamente</a></p>
            <div class="cancel-box">
                <form method="post" action="?action=cancel_lock&id=<?= $doc_info['id'] ?>" onsubmit="return confirm('Tem certeza que deseja devolver o documento sem assinar?');">
                    <button type="submit" class="btn btn-danger" style="padding: 6px 12px; font-size: 0.9em;">Desistir / Devolver sem assinar</button>
                </form>
            </div>

        <?php elseif ($is_locked): ?>
            <p class="status-ocupado">Em posse de: <?= $safe_locked_by ?></p>
            <p>A reserva exclusiva expira em <b><?= $minutos_restantes ?> minutos</b>.</p>
            <hr style="margin: 20px 0; border: 0; border-top: 1px solid #ddd;">
            <div style="background: #f8f9fa; padding: 15px; border-radius: 6px;">
                <p style="margin-top: 0; font-size: 0.95em;"><strong>É você que reservou e mudou de dispositivo?</strong><br>
                <span style="font-size: 0.9em; color: #666;">Digite o PIN de 6 dígitos gerado no outro aparelho:</span></p>
                <form method="post" action="?action=assume_pin&id=<?= $doc_info['id'] ?>" style="display: flex; gap: 10px;">
                    <input type="text" name="pin" maxlength="6" pattern="\d{6}" required placeholder="Ex: 123456" style="margin-bottom: 0;">
                    <button type="submit" class="btn" style="background: #17a2b8;">Acessar</button>
                </form>
            </div>
            <p style="text-align: center; margin-top: 20px;"><a href="?id=<?= $doc_info['id'] ?>">Atualizar página</a></p>

        <?php else: ?>
            <p class="status-livre">Status: Livre para assinatura</p>
            <?php if ($can_override): ?><div class="aviso">O tempo de reserva expirou. Você pode assumir.</div><?php endif; ?>
            <form method="post" action="?action=lock&id=<?= $doc_info['id'] ?>">
                <label>Seu Nome:</label>
                <input type="text" name="usuario" required maxlength="100" placeholder="Ex: João da Silva">
                <button type="submit" class="btn" style="width: 100%;">Pegar arquivo para Assinar</button>
            </form>
        <?php endif; ?>
    </div>

    <?php
    $stmt_log = $pdo->prepare("SELECT usuario, signed_at FROM signature_log WHERE doc_id = ? ORDER BY signed_at ASC");
    $stmt_log->execute([$doc_info['id']]);
    $logs = $stmt_log->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <?php if (count($logs) > 0): ?>
        <div class="card" style="margin-top: 20px;">
            <h3 style="margin-top: 0;">Histórico de Devoluções</h3>
            <ul style="list-style-type: none; padding-left: 0; margin-bottom: 0;">
                <?php foreach ($logs as $log): ?>
                    <li style="padding: 8px 0; border-bottom: 1px solid #eee;">
                        ✅ <b><?= htmlspecialchars($log['usuario'], ENT_QUOTES, 'UTF-8') ?></b> devolveu o arquivo em 
                        <?= date('d/m/Y H:i', $log['signed_at']) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
<?php else: ?>
    <p>Documento não encontrado.</p>
<?php endif; ?>

</body>
</html>