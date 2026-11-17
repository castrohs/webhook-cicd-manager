<?php

namespace App\Controllers;

class Auth extends BaseController
{
    public function login()
    {
        // Se já logado, redireciona para admin
        if (session()->get('is_logged_in')) {
            return redirect()->to('/admin');
        }

        return view('admin/login');
    }

    public function process()
    {
        $username = (string) $this->request->getPost('username');
        $password = (string) $this->request->getPost('password');
        $totpCode = (string) ($this->request->getPost('totp_code') ?? '');

        $usersFile = WRITEPATH . 'users.json';
        if (file_exists($usersFile)) {
            $users = json_decode(file_get_contents($usersFile), true);
            if (is_array($users)) {
                foreach ($users as $idx => $user) {
                    if ($user['username'] === $username && password_verify($password, $user['password_hash'])) {
                        $has2fa = !empty($user['two_factor_enabled']) && !empty($user['totp_secret']);
                        $usedRecovery = false;
                        $remainingCodes = [];

                        if ($has2fa) {
                            $totpOk = \App\Libraries\Totp::verify((string) $user['totp_secret'], $totpCode);
                            if (!$totpOk) {
                                $recoveryHashes = is_array($user['recovery_codes_hashes'] ?? null) ? $user['recovery_codes_hashes'] : [];
                                $recoveryResult = \App\Libraries\Totp::consumeRecoveryCode($totpCode, $recoveryHashes);
                                if (!$recoveryResult['matched']) {
                                    session()->setFlashdata('error', 'Código 2FA (TOTP) ou código de emergência inválido.');
                                    return redirect()->to('/login');
                                }
                                $usedRecovery = true;
                                $remainingCodes = $recoveryResult['remaining'];
                                $users[$idx]['recovery_codes_hashes'] = $remainingCodes;
                                file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                            }
                        }

                        session()->regenerate(true);
                        session()->set([
                            'is_logged_in' => true,
                            'username'     => $username,
                        ]);
                        if ($usedRecovery) {
                            session()->setFlashdata('message', 'Login realizado com código de recuperação de emergência. Restam ' . count($remainingCodes) . ' código(s).');
                        }
                        return redirect()->to('/admin');
                    }
                }
            }
        }

        session()->setFlashdata('error', 'Usuário ou senha inválidos.');
        return redirect()->to('/login');
    }

    public function logout()
    {
        session()->destroy();
        return redirect()->to('/login');
    }
}
