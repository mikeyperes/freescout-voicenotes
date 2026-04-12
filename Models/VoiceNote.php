<?php

namespace Modules\HexawebVoiceNotes\Models;

use Illuminate\Database\Eloquent\Model;

class VoiceNote extends Model
{
    const STORAGE_DIR = "voicenotes";

    protected $table = "voice_notes";

    protected $fillable = [
        "thread_id",
        "conversation_id",
        "file_path",
        "duration_seconds",
        "mime_type",
        "file_size",
        "original_name",
        "token",
        "created_by",
    ];

    public function thread()
    {
        return $this->belongsTo(\App\Thread::class);
    }

    public function conversation()
    {
        return $this->belongsTo(\App\Conversation::class);
    }

    public function creator()
    {
        return $this->belongsTo(\App\User::class, "created_by");
    }

    public function getStoragePath()
    {
        return storage_path("app/private/" . self::STORAGE_DIR . "/" . $this->file_path);
    }

    public function getFormattedDuration()
    {
        $m = floor($this->duration_seconds / 60);
        $s = $this->duration_seconds % 60;
        return $m . ":" . str_pad($s, 2, "0", STR_PAD_LEFT);
    }

    public function generateSignedUrl($expireDays = null)
    {
        $expireDays = $expireDays ?: (int) \Option::get("voicenotes.url_expiry_days", 7);
        $expiresAt = now()->addDays($expireDays)->timestamp;
        $signature = hash_hmac("sha256", $this->id . "|" . $expiresAt, config("app.key"));

        return route("voicenotes.stream", [
            "id"        => $this->id,
            "expires"   => $expiresAt,
            "signature" => $signature,
        ]);
    }

    public static function verifySignature($id, $expires, $signature)
    {
        if (time() > (int) $expires) {
            return false;
        }
        $expected = hash_hmac("sha256", $id . "|" . $expires, config("app.key"));
        return hash_equals($expected, $signature);
    }
}
