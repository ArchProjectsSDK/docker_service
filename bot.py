import os
import sqlite3
import asyncio
from datetime import datetime
from pyrogram import Client, filters
from pyrogram.types import Message

BOT_TOKEN = os.getenv("8680623688:AAHaXKiRee6hY2ZF8GhXyH156A9iexIn6BY")
API_ID    = int(os.getenv("27605865"))
API_HASH  = os.getenv("f76cf301b264391c6ed01747638adecc")

DOWNLOADS_DIR = "downloads"
DB_PATH       = "files.db"

os.makedirs(DOWNLOADS_DIR, exist_ok=True)

# ── DB ──────────────────────────────────────────────────────
def get_db():
    db = sqlite3.connect(DB_PATH)
    db.execute("PRAGMA journal_mode=WAL")
    db.execute("""
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
    """)
    db.commit()
    return db

def is_duplicate(db, message_id: int) -> bool:
    cur = db.execute("SELECT 1 FROM files WHERE message_id=? LIMIT 1", (message_id,))
    return cur.fetchone() is not None

def save_record(db, data: dict):
    db.execute("""
        INSERT OR IGNORE INTO files
            (message_id, sender_id, file_name, duration, size, path, created_at)
        VALUES
            (:message_id, :sender_id, :file_name, :duration, :size, :path, :created_at)
    """, data)
    db.commit()

def unique_path(file_name: str) -> str:
    base, ext = os.path.splitext(file_name)
    path = os.path.join(DOWNLOADS_DIR, file_name)
    i = 1
    while os.path.exists(path):
        path = os.path.join(DOWNLOADS_DIR, f"{base}_{i}{ext}")
        i += 1
    return path

# ── BOT ─────────────────────────────────────────────────────
app = Client("bot", bot_token=BOT_TOKEN, api_id=API_ID, api_hash=API_HASH)
db  = get_db()

@app.on_message(filters.audio | filters.voice | filters.document)
async def handle_audio(client: Client, msg: Message):
    # Document bo'lsa faqat audio mime type
    if msg.document:
        mime = msg.document.mime_type or ""
        if not mime.startswith("audio/"):
            await msg.reply("🎵 Menga faqat audio, voice yoki audio fayl yuboring.")
            return

    if is_duplicate(db, msg.id):
        print(f"[SKIP] msg={msg.id}")
        return

    media = msg.audio or msg.voice or msg.document
    file_name = getattr(media, "file_name", None) or f"audio_{msg.id}.ogg"
    duration  = getattr(media, "duration", 0) or 0
    size      = getattr(media, "file_size", 0) or 0

    await msg.reply("⏳ Yuklanmoqda...")

    save_path = unique_path(file_name)

    try:
        await client.download_media(msg, file_name=save_path)

        save_record(db, {
            "message_id": msg.id,
            "sender_id":  msg.from_user.id if msg.from_user else 0,
            "file_name":  file_name,
            "duration":   duration,
            "size":       size,
            "path":       save_path,
            "created_at": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
        })

        print(f"[OK] {file_name}")

        await msg.reply(
            f"✅ Saqlandi!\n\n"
            f"📁 {file_name}\n"
            f"⏱ {duration}s\n"
            f"📦 {round(size / 1024 / 1024, 2)} MB"
        )

    except Exception as e:
        print(f"[ERROR] {e}")
        await msg.reply("❌ Xatolik yuz berdi.")

@app.on_message(filters.text)
async def handle_text(client: Client, msg: Message):
    await msg.reply("🎵 Menga faqat audio, voice yoki audio fayl yuboring.")

# ── RUN ─────────────────────────────────────────────────────
if __name__ == "__main__":
    print("[OK] Bot started")
    app.run()