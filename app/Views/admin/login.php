<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Login - Server Manager</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-900 flex items-center justify-center min-h-screen">
    <div class="bg-gray-800 p-8 rounded-xl border border-gray-700 shadow-2xl w-full max-w-md">
        <div class="text-center mb-8">
            <h1 class="text-3xl font-bold text-blue-500 italic flex items-center justify-center gap-2">
                <span>🚀</span> Server Manager
            </h1>
            <p class="text-gray-400 text-sm mt-2">Acesso restrito</p>
        </div>

        <?php if (session()->getFlashdata('error')): ?>
            <div class="bg-red-900/50 border border-red-500 text-red-200 p-3 rounded mb-6 text-sm text-center">
                <?= session()->getFlashdata('error') ?>
            </div>
        <?php endif; ?>

        <form action="/login/process" method="POST" class="space-y-6">
            <div>
                <label class="block text-gray-400 text-sm font-bold mb-2">Usuário</label>
                <input type="text" name="username" required class="w-full bg-gray-900 border border-gray-700 rounded-lg p-3 text-white focus:border-blue-500 outline-none transition-all">
            </div>
            <div>
                <label class="block text-gray-400 text-sm font-bold mb-2">Senha</label>
                <input type="password" name="password" required class="w-full bg-gray-900 border border-gray-700 rounded-lg p-3 text-white focus:border-blue-500 outline-none transition-all">
            </div>
            <div>
                <label class="block text-gray-400 text-sm font-bold mb-2">Código 2FA ou Emergência <span class="text-xs font-normal text-gray-500">(se ativo)</span></label>
                <input type="text" name="totp_code" autocomplete="one-time-code" maxlength="16" placeholder="000000 ou XXXX-XXXX"
                    class="w-full bg-gray-900 border border-gray-700 rounded-lg p-3 text-white font-mono tracking-widest focus:border-blue-500 outline-none transition-all">
            </div>
            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-500 p-3 rounded-lg text-white font-bold transition-all shadow-lg shadow-blue-900/20">Entrar</button>
        </form>
    </div>
</body>
</html>
