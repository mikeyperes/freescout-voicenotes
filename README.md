# freescout-voicenotes

A FreeScout module that lets staff record a voice note in the reply editor. The recording is converted to MP3 with ffmpeg, stored privately on the help desk, and added to the reply as a link that recipients open in a browser; in FreeScout the link shows as an inline audio player. It does not transcribe audio.

## Identity

- Kind: FreeScout module
- Version: `1.1.1`
- Module name: `HexawebVoiceNotes` (alias `hexawebvoicenotes`)
- Requires: FreeScout; `ffmpeg` on the server for MP3 conversion (without it the original recording format is kept)
- Installed: `Modules/HexawebVoiceNotes` in the FreeScout root

## Features

- **Recorder** (`Resources/js/recorder.js`, added to every page through the `javascript` action): records from the microphone in the reply editor and uploads the recording.
- **Upload and conversion** (`Http/Controllers/VoiceNoteController.php`, `upload()`): checks the file type and size, converts to MP3 at the set bit rate, reads its length, saves it under `storage/app/private/` and records it in `voice_notes`.
- **Signed playback links** (`stream()`): `/voicenotes/{id}/stream` plays the file only with a valid, unexpired signature; anyone holding the link can play it until it expires.
- **Inline players** (`Providers/HexawebVoiceNotesServiceProvider.php`, `thread.body_output` filter): voice note links in a conversation are shown as audio players.
- **Settings page** (`/manage/voicenotes`, admins only), linked from the Manage menu.

## How it is used

- `POST /voicenotes/upload`: signed-in staff; used by the recorder.
- `GET /voicenotes/{id}/stream?expires=…&signature=…`: anyone with a valid link.
- `GET /manage/voicenotes`, `POST /manage/voicenotes`: admins.

## Settings

FreeScout options, changed on `/manage/voicenotes`:

- `voicenotes.max_duration` (`300`): longest recording, in seconds.
- `voicenotes.max_file_size_mb` (`25`): largest upload.
- `voicenotes.bitrate` (`128k`): MP3 bit rate.
- `voicenotes.url_expiry_days` (`7`): how long a playback link works.

## Stored data

- `voice_notes`: one row per recording (thread and conversation, file path, length, type, size, original name, unique token, who made it).
- Audio files in `storage/app/private/`.

## Extension points

Uses FreeScout's `javascript` and `menu.manage.after_mailboxes` actions and the `thread.body_output` and `laravel_route_js.routes` filters.

## Code layout

- `Http/`: routes and the controller.
- `Models/VoiceNote.php`: the record, storage path and link signing.
- `Providers/`: the table, menu link, recorder script and inline players.
- `Resources/`: the recorder script and settings view.
- `Database/Migrations/`: the `voice_notes` table.

## Tests

The module has no automated tests.
