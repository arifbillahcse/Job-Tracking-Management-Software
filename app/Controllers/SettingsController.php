<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\SettingsService;
use DateTimeZone;

/**
 * Application settings (settings.manage). Values are validated by SettingsService; every change is audited.
 */
final class SettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        return $this->view('settings/edit', [
            'title' => 'Settings',
            'values' => SettingsService::editable(),
            'timezones' => DateTimeZone::listIdentifiers(),
            'pageSizes' => SettingsService::PAGE_SIZES,
        ]);
    }

    public function update(Request $request): Response
    {
        $result = SettingsService::validate($request->allPost());
        if ($result['errors'] !== []) {
            return $this->redirectWithErrors($request, array_map(static fn (string $m): array => [$m], $result['errors']), '/settings');
        }

        $changes = SettingsService::update($result['values']);
        if ($changes['new'] === []) {
            $this->flash('info', 'Nothing changed.');
        } else {
            $this->flash('success', 'Settings saved. ' . ((isset($changes['new']['ready_threshold_days']) || isset($changes['new']['section_basis'])) ? 'Jobs have been re-sorted into their sections using the new rule.' : ''));
        }
        return $this->redirect('/settings');
    }

    public function storageEdit(Request $request): Response
    {
        return $this->view('settings/storage', ['title' => 'Storage fee', 'values' => SettingsService::editable()]);
    }

    /** Only the storage fee keys are read; anything else in the request is ignored. */
    public function storageUpdate(Request $request): Response
    {
        $result = SettingsService::validate($request->allPost() + SettingsService::editable());
        $errors = array_intersect_key($result['errors'], array_flip(SettingsService::STORAGE_KEYS));
        if ($errors !== []) {
            return $this->redirectWithErrors($request, array_map(static fn (string $m): array => [$m], $errors), '/settings/storage-fee');
        }

        $changes = SettingsService::update(array_intersect_key($result['values'], array_flip(SettingsService::STORAGE_KEYS)));
        $this->flash($changes['new'] === [] ? 'info' : 'success', $changes['new'] === [] ? 'Nothing changed.' : 'Storage fee settings saved. Open jobs have been re-calculated.');
        return $this->redirect('/settings/storage-fee');
    }
}
