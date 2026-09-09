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
        $username = $this->request->getPost('username');
        $password = $this->request->getPost('password');

        $usersFile = WRITEPATH . 'users.json';
        if (file_exists($usersFile)) {
            $users = json_decode(file_get_contents($usersFile), true);
            if (is_array($users)) {
                foreach ($users as $user) {
                    if ($user['username'] === $username && password_verify($password, $user['password_hash'])) {
                        session()->set([
                            'is_logged_in' => true,
                            'username'     => $username
                        ]);
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
