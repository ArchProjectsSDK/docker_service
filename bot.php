<?php
declare(strict_types=1);

require 'madeline.php';

const DOWNLOADS_DIR = __DIR__ . '/downloads';
const DB_PATH       = __DIR__ . '/files.db';

$token = getenv('BOT_TOKEN');
if (!$token) die("BOT_TOKEN env yo'q\n");

/* ─── DB ──────────────────────────────────────────────────── */
$db = new PDO('sqlite:' . DB_PATH, options: [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$db->exec("PRAGMA journal_mode=WAL");
$db->exec("PRAGMA synchronous=NORMAL");
$db->exec("
    CREATE TABLE IF NOT EXISTS files (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        message_id INTEGER NOT NULL,
        sender_id  INTEGER,
        file_name  TEXT    NOT NULL,
        duration   INTEGER NOT NULL DEFAULT 0,
        size       INTEGER NOT NULL DEFAULT 0,
        path       TEXT    NOT NULL,
        created_at TEXT    NOT NULL,
        UNIQUE(message_id)
    )
");

/* ─── HELPERS ─────────────────────────────────────────────── */
function extractMeta(array $media): array
{
    $fileName = 'audio_' . time() . '.ogg';
    $duration = 0;
    $size     = (int) ($media['document']['size'] ?? 0);

    foreach ($media['document']['attributes'] ?? [] as $attr) {
        if ($attr['_'] === 'documentAttributeFilename') {
            $fileName = $attr['file_name'];
        }
        if ($attr['_'] === 'documentAttributeAudio') {
            $duration = (int) ($attr['duration'] ?? 0);
        }
    }
    return compact('fileName', 'duration', 'size');
}

function uniquePath(string $dir, string $fileName): string
{
    $base = pathinfo($fileName, PATHINFO_FILENAME);
    $ext  = pathinfo($fileName, PATHINFO_EXTENSION);
    $path = $dir . '/' . $fileName;
    $i    = 1;
    while (file_exists($path)) {
        $path = $dir . '/' . $base . '_' . $i . ($ext ? ".$ext" : '');
        $i++;
    }
    return $path;
}

/* ─── BOT ─────────────────────────────────────────────────── */
if (!is_dir(DOWNLOADS_DIR)) mkdir(DOWNLOADS_DIR, 0755, true);

$MadelineProto = new \danog\MadelineProto\API('bot.session');
$MadelineProto->botLogin($token);

echo "[OK] Bot started\n";

$offset = 0;

while (true) {
    try {
        $updates = $MadelineProto->getUpdates(['offset' => $offset, 'limit' => 100]);

        foreach ($updates as $update) {
            $offset = $update['update_id'] + 1;

            $msg = $update['update']['message'] ?? null;
            if (!$msg) continue;

            $messageId = (int) ($msg['id'] ?? 0);
            $senderId  = (int) ($msg['from_id']['user_id'] ?? 0);
            $media     = $msg['media'] ?? null;

            if (!$media || !in_array($media['_'], [
                'messageMediaDocument',
                'messageMediaAudio',
                'messageMediaVoice',
            ], true)) {
                $MadelineProto->messages->sendMessage([
                    'peer'            => $msg['peer_id'],
                    'message'         => '🎵 Menga faqat audio yuboring.',
                    'reply_to_msg_id' => $messageId,
                ]);
                continue;
            }

            // Duplicate check
            $stmt = $db->prepare("SELECT 1 FROM files WHERE message_id=? LIMIT 1");
            $stmt->execute([$messageId]);
            if ($stmt->fetchColumn()) {
                echo "[SKIP] msg=$messageId\n";
                continue;
            }

            ['fileName' => $fileName, 'duration' => $duration, 'size' => $size]
                = extractMeta($media);

            $savePath = uniquePath(DOWNLOADS_DIR, $fileName);

            $MadelineProto->messages->sendMessage([
                'peer'            => $msg['peer_id'],
                'message'         => '⏳ Yuklanmoqda...',
                'reply_to_msg_id' => $messageId,
            ]);

            $MadelineProto->downloadToFile($media, $savePath);

            $db->prepare("
                INSERT OR IGNORE INTO files
                    (message_id, sender_id, file_name, duration, size, path, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ")->execute([$messageId, $senderId, $fileName, $duration, $size, $savePath, date('Y-m-d H:i:s')]);

            echo "[OK] $fileName\n";

            $MadelineProto->messages->sendMessage([
                'peer'            => $msg['peer_id'],
                'message'         => "✅ Saqlandi!\n\n📁 $fileName\n⏱ {$duration}s\n📦 " . round($size/1024/1024, 2) . " MB",
                'reply_to_msg_id' => $messageId,
            ]);
        }

        sleep(2);

    } catch (\danog\MadelineProto\Exception $e) {
        echo "[MTProto] " . $e->getMessage() . "\n";
        sleep(5);
    } catch (PDOException $e) {
        echo "[DB] " . $e->getMessage() . "\n";
        sleep(5);
    } catch (Throwable $e) {
        echo "[ERROR] " . $e->getMessage() . "\n";
        sleep(5);
    }
}