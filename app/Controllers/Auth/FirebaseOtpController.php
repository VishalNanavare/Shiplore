<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * FirebaseOtpController — a self-contained validator for the saved Firebase
 * config. The page (admin/firebase-otp-test) loads the Firebase JS SDK with the
 * saved web config, sends a real OTP from the browser, then POSTs the returned
 * ID token here for server-side verification — proving phone-OTP works end to end
 * before it's wired into a real login flow. Gated by webAuth (admin group).
 */
final class FirebaseOtpController extends BaseController
{
    public function show(): string
    {
        $cfg     = service('integrationRepository')->config('firebase');
        $project = trim((string) ($cfg['project_id'] ?? ''));

        return view('auth/firebase_otp_test', [
            'title' => 'Firebase OTP Test',
            'fb'    => [
                'apiKey'            => trim((string) ($cfg['web_api_key'] ?? '')),
                'authDomain'        => $project !== '' ? $project . '.firebaseapp.com' : '',
                'projectId'         => $project,
                'messagingSenderId' => trim((string) ($cfg['sender_id'] ?? '')),
                'appId'             => trim((string) ($cfg['app_id'] ?? '')),
            ],
        ]);
    }

    public function verify(): ResponseInterface
    {
        $idToken = (string) $this->request->getPost('id_token');
        $claims  = service('firebaseVerifier')->verify($idToken);

        if ($claims === null) {
            return $this->response->setStatusCode(401)->setJSON([
                'ok'      => false,
                'message' => 'Server could not verify the token. Check Project ID is saved and Phone sign-in is enabled in Firebase.',
                'csrf'    => csrf_hash(),
            ]);
        }

        return $this->response->setJSON([
            'ok'    => true,
            'phone' => (string) ($claims['phone_number'] ?? ''),
            'uid'   => (string) ($claims['sub'] ?? ''),
            'csrf'  => csrf_hash(),
        ]);
    }
}
