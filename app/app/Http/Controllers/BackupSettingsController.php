<?php

namespace App\Http\Controllers;

use App\Backups\R2Settings;
use App\Http\Requests\R2BackupRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class BackupSettingsController extends Controller
{
    public function index(R2Settings $settings)
    {
        Gate::authorize('manage-platform-backups');

        return response()->view('backups.settings', ['settings' => $settings->summary(), 'health' => $settings->health()])->header('Cache-Control', 'no-store, private');
    }

    public function update(R2BackupRequest $request, R2Settings $settings)
    {
        $settings->save($request->validated(), $request->user());

        return redirect()->route('backups.settings')->with('success', 'Configuração R2 salva com criptografia. Os campos em branco preservam os dados já cadastrados.');
    }

    public function request(Request $request, R2Settings $settings)
    {
        Gate::authorize('manage-platform-backups');
        $settings->requestBackup($request->user());

        return redirect()->route('backups.settings')->with('success', 'Backup solicitado. Acompanhe a verificação externa neste painel.');
    }
}
