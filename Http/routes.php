<?php

// ── Authenticated routes (agents only) ──
Route::group(['middleware' => ['web', 'auth']], function () {
    Route::post('/voicenotes/upload', [
        'uses'    => 'Modules\HexawebVoiceNotes\Http\Controllers\VoiceNoteController@upload',
        'laroute' => true,
    ])->name('voicenotes.upload');
});

// ── Admin-only settings routes ──
Route::group(['middleware' => ['web', 'auth', 'roles'], 'roles' => ['admin']], function () {
    Route::get('/manage/voicenotes', [
        'uses' => 'Modules\HexawebVoiceNotes\Http\Controllers\VoiceNoteController@settings',
    ])->name('voicenotes.settings');

    Route::post('/manage/voicenotes', [
        'uses' => 'Modules\HexawebVoiceNotes\Http\Controllers\VoiceNoteController@saveSettings',
    ])->name('voicenotes.settings.save');
});

// ── Public streaming route (signed URL, no auth) ──
Route::get('/voicenotes/{id}/stream', [
    'uses'       => 'Modules\HexawebVoiceNotes\Http\Controllers\VoiceNoteController@stream',
    'middleware'  => ['web'],
    'laroute'    => true,
])->name('voicenotes.stream')->where('id', '[0-9]+');
