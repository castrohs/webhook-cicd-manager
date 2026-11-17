<!DOCTYPE html>
<html lang="pt-br">

<head>
    <meta charset="UTF-8">
    <title>Server Manager Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        pre {
            font-family: 'Fira Code', monospace;
        }

        .log-error {
            color: #f87171;
        }

        .log-success {
            color: #4ade80;
        }

        .log-info {
            color: #60a5fa;
        }
    </style>
</head>

<body class="bg-gray-900 text-gray-100 min-h-screen">
    <div class="container mx-auto px-4 py-8">
        <header class="flex justify-between items-center mb-8 border-b border-gray-700 pb-4">
            <h1 class="text-3xl font-bold text-blue-500 italic flex items-center gap-2">
                <span>🚀</span> Server Manager <span
                    class="text-xs bg-gray-800 text-gray-400 px-2 py-1 rounded ml-2">CI4 Edition</span>
            </h1>
            <div class="flex items-center gap-4 text-sm text-gray-400">
                <span class="bg-blue-900/50 text-blue-300 px-3 py-1 rounded-full border border-blue-500/30">👤
                    <?= session()->get('username') ?></span>
                <a href="/logout"
                    class="hover:text-white transition-colors bg-red-900/30 px-3 py-1 rounded border border-red-500/30 hover:bg-red-600">Sair</a>
            </div>
        </header>

        <?php if ($message): ?>
            <div class="mb-6 p-4 rounded bg-blue-900/50 border border-blue-500 text-blue-200 animate-pulse">
                <?= $message ?>
            </div>
        <?php endif; ?>

        <!-- Tabs Navigation -->
        <div class="flex items-center gap-8 mb-6 border-b border-gray-700 pb-2">
            <button onclick="switchTab('logs')" id="tab-btn-logs"
                class="text-lg font-bold uppercase tracking-widest text-white transition-all">Arquivos de log</button>
            <button onclick="switchTab('comandos')" id="tab-btn-comandos"
                class="text-lg font-bold uppercase tracking-widest text-gray-500 hover:text-gray-300 transition-all">comandos</button>
            <button onclick="switchTab('configs')" id="tab-btn-configs"
                class="text-lg font-bold uppercase tracking-widest text-gray-500 hover:text-gray-300 transition-all">projects.json</button>
            <button onclick="switchTab('servidores')" id="tab-btn-servidores"
                class="text-lg font-bold uppercase tracking-widest text-gray-500 hover:text-gray-300 transition-all">servidores</button>
            <?php
                $auditPassed = (int) ($securityAudit['passed'] ?? 0);
                $auditTotal  = (int) ($securityAudit['total'] ?? 4);
                $auditStatus = $securityAudit['level_status'] ?? 'warn';
                $pillColor = match ($auditStatus) {
                    'ok'     => 'bg-green-900/60 text-green-300 border-green-500/40',
                    'danger' => 'bg-red-900/60 text-red-300 border-red-500/40',
                    default  => 'bg-yellow-900/60 text-yellow-300 border-yellow-500/40',
                };
            ?>
            <button onclick="switchTab('seguranca')" id="tab-btn-seguranca"
                class="text-lg font-bold uppercase tracking-widest text-gray-500 hover:text-gray-300 transition-all flex items-center gap-2">
                <span>segurança</span>
                <span id="security-tab-pill" class="text-[11px] font-mono px-2 py-0.5 rounded-full border <?= $pillColor ?>">
                    <?= $auditPassed ?>/<?= $auditTotal ?>
                </span>
            </button>
        </div>

        <!-- Tab: Logs -->
        <div id="tab-content-logs" class="grid grid-cols-1 lg:grid-cols-4 gap-8">
            <!-- Sidebar: Logs -->
            <div class="lg:col-span-1">
                <section>
                    <nav class="space-y-2 max-h-[700px] overflow-y-auto custom-scrollbar pr-2">
                        <?php if (empty($logs)): ?>
                            <p class="text-gray-600 italic text-sm text-center py-4 bg-gray-800/50 rounded-lg">Nenhum log
                                ainda.</p>
                        <?php else: ?>
                            <?php foreach ($logs as $logPath): ?>
                                <?php $name = basename($logPath); ?>
                                <a href="?log=<?= urlencode($name) ?>&tab=logs"
                                    class="block p-3 rounded-lg transition-all <?= $selectedLog === $name ? 'bg-blue-600 text-white shadow-lg' : 'bg-gray-800 hover:bg-gray-700 text-gray-400 hover:text-gray-100' ?>">
                                    <div class="font-medium truncate text-sm"><?= str_replace(['deploy_', '.log'], '', $name) ?>
                                    </div>
                                    <div class="text-[10px] opacity-50"><?= date("d/m/Y H:i", filemtime($logPath)) ?></div>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </nav>
                </section>
            </div>

            <!-- Main: Visualizador de Log -->
            <div class="lg:col-span-3">
                <?php if ($selectedLog): ?>
                    <div
                        class="bg-gray-800 rounded-2xl overflow-hidden border border-gray-700 shadow-2xl flex flex-col h-[700px]">
                        <div
                            class="bg-gray-800/80 backdrop-blur-md px-6 py-4 border-b border-gray-700 flex justify-between items-center shrink-0">
                            <div>
                                <span class="text-xs text-gray-500 uppercase font-bold tracking-widest">Visualizando
                                    Log:</span>
                                <h3 class="text-blue-400 font-mono text-sm mt-1"><?= $selectedLog ?></h3>
                            </div>
                            <div class="flex items-center gap-2">
                                <button onclick="copyLog()"
                                    class="bg-gray-700 hover:bg-gray-600 text-gray-200 text-xs px-4 py-2 rounded-lg transition-all flex items-center gap-2 font-bold">
                                    📋 <span>Copiar</span>
                                </button>
                                <button onclick="location.reload()"
                                    class="bg-gray-700 hover:bg-gray-600 text-gray-200 text-xs px-4 py-2 rounded-lg transition-all flex items-center gap-2 font-bold">
                                    🔄 <span>Atualizar</span>
                                </button>
                            </div>
                        </div>
                        <div class="p-6 overflow-auto custom-scrollbar flex-grow bg-gray-900/30">
                            <pre id="log-content" class="text-sm leading-relaxed whitespace-pre-wrap font-mono"><?php
                            $display = htmlspecialchars($content);
                            $display = str_replace('SUCESSO', '<span class="log-success font-bold underline">SUCESSO</span>', $display);
                            $display = str_replace('ERRO FATAL', '<span class="log-error font-bold underline">ERRO FATAL</span>', $display);
                            $display = str_replace('INICIANDO', '<span class="log-info font-bold">INICIANDO</span>', $display);
                            echo $display;
                            ?></pre>
                        </div>
                        <?php if (strpos($content, 'finalizado') === false && strpos($content, 'ERRO') === false): ?>
                            <div class="bg-blue-900/30 border-t border-blue-500/30 px-6 py-3 flex items-center justify-between shrink-0"
                                id="auto-reload-banner">
                                <span class="text-xs text-blue-300 animate-pulse font-medium">📡 Deploy em progresso... (Aba de
                                    logs recarregará automaticamente)</span>
                                <script>
                                    setTimeout(() => {
                                        const urlParams = new URLSearchParams(window.location.search);
                                        if (!urlParams.has('tab') || urlParams.get('tab') === 'logs') {
                                            location.reload();
                                        }
                                    }, 4000);
                                </script>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div
                        class="bg-gray-800/30 rounded-2xl p-24 text-center border-2 border-dashed border-gray-700 flex flex-col items-center justify-center h-[700px]">
                        <div
                            class="w-20 h-20 bg-gray-800 rounded-full flex items-center justify-center text-4xl mb-6 shadow-inner text-gray-500">
                            📄</div>
                        <h3 class="text-xl font-bold text-gray-400">Nenhum log selecionado</h3>
                        <p class="text-gray-500 mt-2 max-w-sm mx-auto text-sm leading-relaxed text-gray-600">Selecione um
                            arquivo na barra lateral para visualizar o progresso dos deploies em tempo real.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tab: Comandos -->
        <div id="tab-content-comandos" class="hidden">
            <div class="grid grid-cols-1 lg:grid-cols-4 gap-8">

                <!-- Menu Lateral (Sidebar) -->
                <div class="lg:col-span-1">
                    <nav class="space-y-2 bg-gray-800 p-4 rounded-xl border border-gray-700">
                        <button onclick="showCommand('cmd-create-host')" id="btn-cmd-create-host"
                            class="w-full text-left p-3 rounded-lg transition-all bg-blue-600 text-white shadow-lg font-medium text-sm flex items-center gap-2">📂
                            Criar Host Simples</button>
                        <button onclick="showCommand('cmd-create-deploy')" id="btn-cmd-create-deploy"
                            class="w-full text-left p-3 rounded-lg transition-all bg-gray-800 hover:bg-gray-700 text-gray-400 hover:text-gray-100 font-medium text-sm flex items-center gap-2">🌐
                            Criar Host + Deploy</button>
                        <button onclick="showCommand('cmd-redeploy')" id="btn-cmd-redeploy"
                            class="w-full text-left p-3 rounded-lg transition-all bg-gray-800 hover:bg-gray-700 text-gray-400 hover:text-gray-100 font-medium text-sm flex items-center gap-2">🚀
                            Carregar Deploy (Redeploy)</button>
                        <button onclick="showCommand('cmd-ssh')" id="btn-cmd-ssh"
                            class="w-full text-left p-3 rounded-lg transition-all bg-gray-800 hover:bg-gray-700 text-gray-400 hover:text-gray-100 font-medium text-sm flex items-center gap-2">🛡️
                            Gerar Chave SSH</button>
                        <button onclick="showCommand('cmd-permissions')" id="btn-cmd-permissions"
                            class="w-full text-left p-3 rounded-lg transition-all bg-gray-800 hover:bg-gray-700 text-gray-400 hover:text-gray-100 font-medium text-sm flex items-center gap-2">🔑
                            Resetar Permissões</button>
                        <button onclick="showCommand('cmd-ssl')" id="btn-cmd-ssl"
                            class="w-full text-left p-3 rounded-lg transition-all bg-gray-800 hover:bg-gray-700 text-gray-400 hover:text-gray-100 font-medium text-sm flex items-center gap-2">🔒
                            Forçar Instalação SSL</button>
                        <button onclick="showCommand('cmd-logs')" id="btn-cmd-logs"
                            class="w-full text-left p-3 rounded-lg transition-all bg-gray-800 hover:bg-gray-700 text-gray-400 hover:text-gray-100 font-medium text-sm flex items-center gap-2">🗑️
                            Limpar Logs</button>
                        <button onclick="showCommand('cmd-password')" id="btn-cmd-password"
                            class="w-full text-left p-3 rounded-lg transition-all bg-gray-800 hover:bg-gray-700 text-gray-400 hover:text-gray-100 font-medium text-sm flex items-center gap-2">🔑
                            Alterar Minha Senha</button>
                        <button onclick="showCommand('cmd-info')" id="btn-cmd-info"
                            class="w-full text-left p-3 rounded-lg transition-all bg-gray-800 hover:bg-gray-700 text-gray-400 hover:text-gray-100 font-medium text-sm flex items-center gap-2">💡
                            Dicas e Cheatsheet</button>
                    </nav>
                </div>

                <!-- Área de Conteúdo (Forms) -->
                <div class="lg:col-span-3 bg-gray-800 p-6 rounded-xl border border-gray-700 shadow-xl min-h-[500px]">

                    <div id="cmd-create-host" class="cmd-section ">

                        <div>
                            <h2 class="text-sm font-bold text-gray-400 mb-4 flex items-center gap-2">📂 Criar Host
                                Simples (Sem Deploy)</h2>
                            <form action="/admin/action" method="POST" class="space-y-4">
                                <input type="hidden" name="action" value="create_site">
                                <input type="hidden" name="tab" value="comandos">
                                <div>
                                    <input type="text" name="domain" placeholder="Domínio (ex: site.com)" required
                                        class="w-full bg-gray-900 border border-gray-700 rounded-lg p-2 text-sm text-white focus:border-gray-500 outline-none transition-all">
                                </div>
                                <button type="submit"
                                    class="w-full bg-gray-700 hover:bg-gray-600 p-2 rounded-lg text-sm font-bold transition-all shadow-lg text-white">Criar
                                    Host</button>
                            </form>
                        </div>

                    </div>
                    <div id="cmd-create-deploy" class="cmd-section hidden">

                        <div>
                            <h2 class="text-sm font-bold text-blue-400 mb-4 flex items-center gap-2">🌐 Criar Host +
                                Deploy GitHub</h2>
                            <form action="/admin/action" method="POST" class="space-y-4">
                                <input type="hidden" name="action" value="create_site_github">
                                <input type="hidden" name="tab" value="comandos">
                                <div>
                                    <input type="text" name="domain" placeholder="Domínio (ex: api.site.com)" required
                                        class="w-full bg-gray-900 border border-gray-700 rounded-lg p-2 text-sm text-white focus:border-blue-500 outline-none transition-all">
                                </div>
                                <div>
                                    <input type="text" name="repository" placeholder="Repositório (ex: user/repo)"
                                        required
                                        class="w-full bg-gray-900 border border-gray-700 rounded-lg p-2 text-sm text-white focus:border-blue-500 outline-none transition-all">
                                </div>
                                <div>
                                    <input type="text" name="branch" placeholder="Branch (ex: main)" value="main"
                                        required
                                        class="w-full bg-gray-900 border border-gray-700 rounded-lg p-2 text-sm text-white focus:border-blue-500 outline-none transition-all">
                                </div>
                                <div>
                                    <select name="type" required
                                        class="w-full bg-gray-900 border border-gray-700 rounded-lg p-2 text-sm text-white focus:border-blue-500 outline-none transition-all">
                                        <option value="" disabled selected>Selecione o Tipo de Projeto</option>
                                        <option value="auto">Auto (Laravel/CI/PHP/JS)</option>
                                        <option value="nodejs_bot">Node.js Bot (PM2)</option>
                                        <option value="ngix_stream">Nginx Stream</option>
                                        <option value="html">Site Estático / HTML</option>
                                    </select>
                                </div>
                                <div>
                                    <input type="text" name="pm2_name"
                                        placeholder="Nome no PM2 (Opcional, para Node.js)"
                                        class="w-full bg-gray-900 border border-gray-700 rounded-lg p-2 text-sm text-white focus:border-blue-500 outline-none transition-all">
                                </div>
                                <label class="flex items-center gap-2 text-xs text-gray-300 cursor-pointer select-none">
                                    <input type="checkbox" name="backup_database" value="1"
                                        class="rounded bg-gray-900 border-gray-700 text-blue-600 focus:ring-blue-500">
                                    <span>Ativar backup do banco de dados (<code class="text-gray-400">mysqldump</code>) antes das migrations</span>
                                </label>

                                <div class="pt-2 border-t border-gray-700 mt-2">
                                    <span
                                        class="text-xs text-gray-400 font-bold mb-2 block uppercase tracking-wide">Deploy
                                        Remoto (Opcional - Opção 1)</span>
                                    <div class="grid grid-cols-3 gap-4">
                                        <input type="text" name="ssh_host"
                                            placeholder="IP/Host do Servidor (ex: 192.168.1.100)"
                                            class="w-full bg-gray-900 border border-gray-700 rounded-lg p-2 text-sm text-white focus:border-blue-500 outline-none transition-all">
                                        <input type="text" name="ssh_user" placeholder="Usuário SSH (ex: root)"
                                            class="w-full bg-gray-900 border border-gray-700 rounded-lg p-2 text-sm text-white focus:border-blue-500 outline-none transition-all">
                                        <input type="number" name="proxy_port" placeholder="Porta API (ex: 3000)"
                                            class="w-full bg-gray-900 border border-gray-700 rounded-lg p-2 text-sm text-white focus:border-blue-500 outline-none transition-all">
                                    </div>
                                    <p class="text-[10px] text-gray-500 mt-2">*Se preencher a Porta, o Nginx local fará
                                        Reverse Proxy para o IP informado (ou localhost se vazio).</p>
                                </div>
                                <button type="submit"
                                    class="w-full bg-blue-600 hover:bg-blue-500 p-2 rounded-lg text-sm font-bold transition-all shadow-lg shadow-blue-900/20 text-white">Criar
                                    Host Integrado</button>
                            </form>
                        </div>

                    </div>
                    <div id="cmd-redeploy" class="cmd-section hidden">
                        <div>
                            <h2 class="text-sm font-bold text-blue-400 mb-4 flex items-center gap-2">🚀 Carregar Deploy
                                (Redeploy)</h2>
                            <form action="/admin/action" method="POST" class="space-y-4">
                                <input type="hidden" name="action" value="redeploy">
                                <input type="hidden" name="tab" value="comandos">
                                <select name="domain"
                                    class="w-full bg-gray-900 border border-gray-700 rounded-lg p-2 text-sm text-white outline-none focus:border-blue-500 transition-all"
                                    required>
                                    <option value="">Selecionar projeto (projects.json)...</option>
                                    <?php
                                    $projectsArr = json_decode($projects_json ?? '{}', true) ?? [];
                                    foreach ($projectsArr as $d => $config):
                                        ?>
                                        <option value="<?= htmlspecialchars($d) ?>"><?= htmlspecialchars($d) ?>
                                            (<?= htmlspecialchars($config['repository'] ?? $d) ?>:<?= htmlspecialchars($config['branch'] ?? '') ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit"
                                    class="w-full bg-blue-600 hover:bg-blue-500 p-2 rounded-lg text-sm font-bold transition-all shadow-lg shadow-blue-900/20 text-white">Iniciar
                                    Deploy Manual</button>
                            </form>
                        </div>
                    </div>
                    <div id="cmd-ssh" class="cmd-section hidden">

                        <div>
                            <h2 class="text-sm font-bold text-green-500 mb-4 flex items-center gap-2">🛡️ Gerar Chave
                                SSH</h2>
                            <form action="/admin/action" method="POST" class="space-y-4">
                                <input type="hidden" name="action" value="generate_ssh_key">
                                <input type="hidden" name="tab" value="comandos">
                                <select name="domain"
                                    class="w-full bg-gray-900 border border-gray-700 rounded-lg p-2 text-sm text-white outline-none focus:border-green-500 transition-all">
                                    <option value="">Selecionar projeto...</option>
                                    <?php foreach ($sites as $site): ?>
                                        <option value="<?= $site ?>"><?= $site ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit"
                                    class="w-full bg-green-600 hover:bg-green-500 p-2 rounded-lg text-sm font-bold transition-all text-white shadow-lg shadow-green-900/20">Criar
                                    Chave Automática</button>
                            </form>
                        </div>

                    </div>
                    <div id="cmd-permissions" class="cmd-section hidden">

                        <div>
                            <h2 class="text-sm font-bold text-yellow-500 mb-4 flex items-center gap-2">🔑 Resetar
                                Permissões</h2>
                            <form action="/admin/action" method="POST" class="space-y-4">
                                <input type="hidden" name="action" value="fix_permissions">
                                <input type="hidden" name="tab" value="comandos">
                                <select name="domain"
                                    class="w-full bg-gray-900 border border-gray-700 rounded-lg p-2 text-sm text-white outline-none focus:border-yellow-500 transition-all">
                                    <option value="">Selecionar site...</option>
                                    <?php foreach ($sites as $site): ?>
                                        <option value="<?= $site ?>"><?= $site ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit"
                                    class="w-full bg-yellow-600 hover:bg-yellow-500 p-2 rounded-lg text-sm font-bold transition-all text-white shadow-lg shadow-yellow-900/20">Corrigir
                                    Agora</button>
                            </form>
                        </div>

                    </div>
                    <div id="cmd-ssl" class="cmd-section hidden">

                        <div>
                            <h2 class="text-sm font-bold text-purple-400 mb-4 flex items-center gap-2">🔒 Forçar
                                Instalação SSL</h2>
                            <form action="/admin/action" method="POST" class="space-y-4">
                                <input type="hidden" name="action" value="install_ssl">
                                <input type="hidden" name="tab" value="comandos">
                                <select name="domain"
                                    class="w-full bg-gray-900 border border-gray-700 rounded-lg p-2 text-sm text-white outline-none focus:border-purple-500 transition-all">
                                    <option value="">Selecionar site...</option>
                                    <?php foreach ($sites as $site): ?>
                                        <option value="<?= $site ?>"><?= $site ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit"
                                    class="w-full bg-purple-600 hover:bg-purple-500 p-2 rounded-lg text-sm font-bold transition-all text-white shadow-lg shadow-purple-900/20">Instalar
                                    Certificado SSL</button>
                            </form>
                        </div>

                    </div>
                    <div id="cmd-logs" class="cmd-section hidden">

                        <div>
                            <h2 class="text-sm font-bold text-red-500 mb-4 flex items-center gap-2">🗑️ Limpar Logs</h2>
                            <form action="/admin/action" method="POST" class="space-y-4"
                                onsubmit="return confirm('Tem certeza que deseja apagar todos os logs? Esta ação não pode ser desfeita.');">
                                <input type="hidden" name="action" value="clear_logs">
                                <input type="hidden" name="tab" value="comandos">
                                <button type="submit"
                                    class="w-full bg-red-600 hover:bg-red-500 p-2 rounded-lg text-sm font-bold transition-all text-white shadow-lg shadow-red-900/20">Apagar
                                    Todos os Logs</button>
                            </form>
                        </div>

                    </div>
                    <div id="cmd-password" class="cmd-section hidden">

                        <div>
                            <h2 class="text-sm font-bold text-blue-400 mb-4 flex items-center gap-2">🔑 Alterar Minha
                                Senha</h2>
                            <form action="/admin/action" method="POST" class="space-y-4">
                                <input type="hidden" name="action" value="change_password">
                                <input type="hidden" name="tab" value="comandos">
                                <div>
                                    <input type="password" name="current_password" placeholder="Senha Atual" required
                                        class="w-full bg-gray-900 border border-gray-700 rounded-lg p-2 text-sm text-white focus:border-blue-500 outline-none transition-all">
                                </div>
                                <div>
                                    <input type="password" name="new_password" placeholder="Nova Senha" required
                                        minlength="6"
                                        class="w-full bg-gray-900 border border-gray-700 rounded-lg p-2 text-sm text-white focus:border-blue-500 outline-none transition-all">
                                </div>
                                <div>
                                    <input type="password" name="confirm_password" placeholder="Confirmar Nova Senha"
                                        required minlength="6"
                                        class="w-full bg-gray-900 border border-gray-700 rounded-lg p-2 text-sm text-white focus:border-blue-500 outline-none transition-all">
                                </div>
                                <button type="submit"
                                    class="w-full bg-blue-600 hover:bg-blue-500 p-2 rounded-lg text-sm font-bold transition-all shadow-lg shadow-blue-900/20 text-white">Salvar
                                    Nova Senha</button>
                            </form>
                        </div>

                    </div>
                    <div id="cmd-info" class="cmd-section hidden">
                        <div class="flex items-center justify-between mb-6">
                            <h2 class="text-xl font-bold text-blue-400 flex items-center gap-2">💡 Dicas e Cheatsheet
                            </h2>
                            <button onclick="toggleCheatsheetEdit()"
                                class="bg-gray-700 hover:bg-gray-600 px-4 py-2 rounded-lg text-sm text-white font-bold transition-all flex items-center gap-2">✏️
                                Editar</button>
                        </div>

                        <!-- Modo Visualização -->
                        <div id="cheatsheet-view" class="prose prose-invert max-w-none text-gray-300">
                            <?= $cheatsheet_html ?>
                        </div>

                        <!-- Modo Edição -->
                        <div id="cheatsheet-edit" class="hidden">
                            <form action="/admin/action" method="POST">
                                <input type="hidden" name="action" value="save_cheatsheet">
                                <input type="hidden" name="tab" value="comandos">
                                <textarea id="cheatsheet-editor" name="cheatsheet_html"
                                    class="w-full bg-gray-900 border border-gray-700 rounded-lg p-4 text-gray-200 h-96"><?= htmlspecialchars($cheatsheet_html) ?></textarea>
                                <div class="mt-4 flex justify-end gap-3">
                                    <button type="button" onclick="toggleCheatsheetEdit()"
                                        class="bg-gray-700 hover:bg-gray-600 px-4 py-2 rounded-lg text-sm text-white font-bold transition-all">Cancelar</button>
                                    <button type="submit"
                                        class="bg-blue-600 hover:bg-blue-500 px-4 py-2 rounded-lg text-sm text-white font-bold transition-all shadow-lg shadow-blue-900/20">💾
                                        Salvar Alterações</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab: Configs -->
        <div id="tab-content-configs" class="hidden">
            <form action="/admin/action" method="POST"
                class="bg-gray-800 rounded-2xl overflow-hidden border border-gray-700 shadow-2xl flex flex-col h-[700px]">
                        <input type="hidden" name="action" value="save_projects_json">
                        <input type="hidden" name="tab" value="configs">

                        <!-- Cabeçalho Fixo -->
                        <div
                            class="bg-gray-800/80 backdrop-blur-md px-6 py-4 border-b border-gray-700 flex justify-between items-center shrink-0">
                            <div>
                                <span class="text-xs text-gray-500 uppercase font-bold tracking-widest">Editor de
                                    Configurações</span>
                                <h3 class="text-blue-400 font-mono text-sm mt-1 flex items-center gap-2">🛠️
                                    projects.json</h3>
                            </div>
                            <div class="hidden lg:block text-xs text-gray-400 italic">
                                Edite as configurações diretamente. Mantenha o JSON válido.
                            </div>
                        </div>

                        <!-- Corpo / Editor (Ocupa o resto do espaço) -->
                        <div class="flex-grow flex flex-col bg-gray-900 p-0 overflow-hidden">
                            <textarea name="projects_json"
                                class="flex-grow w-full bg-transparent border-none p-6 text-green-400 font-mono text-sm outline-none resize-none custom-scrollbar"
                                spellcheck="false" required><?= htmlspecialchars($projects_json ?? '{}') ?></textarea>
                        </div>

                        <!-- Rodapé Fixo -->
                        <div
                            class="bg-gray-800/80 backdrop-blur-md px-6 py-4 border-t border-gray-700 shrink-0 flex justify-end">
                            <button type="submit"
                                class="bg-blue-600 hover:bg-blue-500 text-white px-8 py-2 rounded-lg text-sm font-bold shadow-lg transition-all flex items-center gap-2">
                                💾 Salvar Alterações
                            </button>
                        </div>
                    </form>
        </div>

        <!-- Tab: Servidores -->
        <div id="tab-content-servidores" class="hidden">
            <div class="flex justify-between items-center mb-6">
                <div class="flex items-center gap-4">
                    <h2 class="text-xl font-bold text-blue-400">Monitoramento de Recursos</h2>
                    <button onclick="document.getElementById('add-server-modal').classList.remove('hidden')" class="bg-blue-600 hover:bg-blue-500 text-white w-8 h-8 rounded-full flex items-center justify-center font-bold text-lg shadow-lg shadow-blue-900/20 transition-all" title="Adicionar Servidor">
                        +
                    </button>
                </div>
                <button onclick="fetchStats()" class="bg-gray-800 hover:bg-gray-700 px-4 py-2 rounded text-sm text-gray-300 flex items-center gap-2 transition-all">
                    <span id="stats-refresh-icon">🔄</span> Atualizar Agora
                </button>
            </div>
            <div id="servers-grid" class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <!-- Cards renderizados via JS -->
                <div class="col-span-full text-center py-12 text-gray-500 animate-pulse">
                    Carregando estatísticas...
                </div>
            </div>
        </div>

        <!-- Tab: Segurança / Hardening -->
        <div id="tab-content-seguranca" class="hidden space-y-6">
            <?php
                $audit = $securityAudit ?? [];
                $scorePct = (int) ($audit['score_pct'] ?? 0);
                $level = (string) ($audit['level'] ?? 'Modo Rápido');
                $levelStatus = (string) ($audit['level_status'] ?? 'warn');
                $barColor = match ($levelStatus) {
                    'ok'     => 'bg-green-500',
                    'danger' => 'bg-red-500',
                    default  => 'bg-yellow-500',
                };
                $bannerBorder = match ($levelStatus) {
                    'ok'     => 'border-green-500/40 bg-green-950/20',
                    'danger' => 'border-red-500/40 bg-red-950/20',
                    default  => 'border-yellow-500/40 bg-yellow-950/20',
                };
            ?>
            <!-- Resumo de Aderência -->
            <div class="rounded-2xl p-6 border <?= $bannerBorder ?> shadow-xl">
                <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div>
                        <div class="text-xs uppercase tracking-widest text-gray-400 font-bold">Diagnóstico de Aderência &amp; Hardening</div>
                        <h2 id="audit-level-title" class="text-2xl font-bold text-white mt-1"><?= esc($level) ?></h2>
                        <p class="text-sm text-gray-400 mt-1">
                            Processo web atual: <code class="text-blue-300 bg-gray-800 px-1.5 py-0.5 rounded"><?= esc($audit['process_user'] ?? 'www-data') ?></code>
                            · Script alvo: <code class="text-blue-300 bg-gray-800 px-1.5 py-0.5 rounded"><?= esc($audit['script_path'] ?? '/opt/deployhub/deploy.sh') ?></code>
                            · Verificado em: <span id="audit-checked-at"><?= esc($audit['checked_at'] ?? '') ?></span>
                        </p>
                    </div>
                    <div class="flex items-center gap-4">
                        <div class="text-right">
                            <div class="text-2xl font-mono font-bold text-white" id="audit-score-text"><?= (int) ($audit['passed'] ?? 0) ?>/<?= (int) ($audit['total'] ?? 4) ?></div>
                            <div class="text-xs text-gray-400">Camadas aderentes</div>
                        </div>
                        <button onclick="refreshSecurityAudit()" id="btn-refresh-audit"
                            class="bg-blue-600 hover:bg-blue-500 text-white text-xs font-bold px-4 py-2.5 rounded-lg transition-all shadow-lg flex items-center gap-2">
                            <span id="audit-refresh-icon">🔄</span>
                            <span>Reexecutar Diagnóstico</span>
                        </button>
                    </div>
                </div>
                <div class="w-full bg-gray-900 rounded-full h-2.5 mt-5 overflow-hidden">
                    <div id="audit-score-bar" class="<?= $barColor ?> h-2.5 rounded-full transition-all duration-500" style="width: <?= $scorePct ?>%"></div>
                </div>
            </div>

            <!-- Checklist das 4 Camadas -->
            <div id="security-checks-list" class="grid grid-cols-1 gap-4">
                <?php foreach (($audit['checks'] ?? []) as $check): ?>
                    <?php
                        $status = $check['status'] ?? 'warn';
                        $cardBorder = match ($status) {
                            'ok'     => 'border-green-500/30',
                            'danger' => 'border-red-500/40',
                            default  => 'border-yellow-500/30',
                        };
                        $badgeStyle = match ($status) {
                            'ok'     => 'bg-green-900/50 text-green-300 border-green-500/30',
                            'danger' => 'bg-red-900/50 text-red-300 border-red-500/40',
                            default  => 'bg-yellow-900/50 text-yellow-300 border-yellow-500/30',
                        };
                        $checkId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($check['id'] ?? 'check'));
                    ?>
                    <div class="bg-gray-800 rounded-2xl p-5 border <?= $cardBorder ?> shadow-lg">
                        <div class="flex flex-col md:flex-row md:items-start justify-between gap-4">
                            <div class="space-y-1">
                                <div class="flex flex-wrap items-center gap-3">
                                    <h3 class="text-base font-bold text-white"><?= esc($check['title'] ?? '') ?></h3>
                                    <span class="text-xs font-bold px-2.5 py-1 rounded-full border <?= $badgeStyle ?>">
                                        <?= esc($check['badge'] ?? '') ?>
                                    </span>
                                </div>
                                <p class="text-xs text-gray-400"><?= esc($check['description'] ?? '') ?></p>
                                <p class="text-sm text-gray-200 pt-1"><?= esc($check['details'] ?? '') ?></p>
                            </div>

                            <?php if (!empty($check['fix_command'])): ?>
                                <button onclick="toggleFixBox('fix-box-<?= $checkId ?>')"
                                    class="shrink-0 bg-gray-900 hover:bg-gray-700 text-yellow-300 border border-yellow-500/40 text-xs font-bold px-4 py-2 rounded-lg transition-all">
                                    🛠️ Corrigir agora
                                </button>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($check['fix_command'])): ?>
                            <div id="fix-box-<?= $checkId ?>" class="hidden mt-4 bg-gray-950 border border-gray-700 rounded-xl p-4">
                                <div class="flex items-center justify-between mb-2">
                                    <span class="text-xs font-bold uppercase tracking-wider text-gray-400">Cole no terminal SSH da VPS:</span>
                                    <button onclick="copyCommandText('fix-cmd-<?= $checkId ?>', this)"
                                        class="bg-gray-800 hover:bg-gray-700 text-gray-200 text-xs px-3 py-1 rounded border border-gray-600 transition-all">
                                        📋 Copiar comando
                                    </button>
                                </div>
                                <pre id="fix-cmd-<?= $checkId ?>" class="text-xs text-green-400 font-mono whitespace-pre-wrap overflow-x-auto"><?= esc($check['fix_command']) ?></pre>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Bloco: Autenticação em 2 Etapas (TOTP Nativo) -->
            <div class="bg-gray-800 rounded-2xl p-6 border border-gray-700 shadow-xl space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                    <div>
                        <h3 class="text-base font-bold text-blue-400">🔐 Autenticação em 2 Etapas (TOTP Nativo — Zero Dependências)</h3>
                        <p class="text-xs text-gray-400 mt-0.5">Compatível com Google Authenticator, Authy, 1Password e Bitwarden. Nenhum pacote externo necessário.</p>
                    </div>
                    <span id="totp-status-badge" class="text-xs font-bold px-3 py-1 rounded-full border <?= !empty($currentUser2faEnabled) ? 'bg-green-900/50 text-green-300 border-green-500/30' : 'bg-yellow-900/50 text-yellow-300 border-yellow-500/30' ?>">
                        <?= !empty($currentUser2faEnabled) ? '🟢 2FA Ativo (' . esc($currentUser2faIssuer ?? 'DeployHub') . ')' : '🟡 2FA Inativo para ' . esc(session()->get('username')) ?>
                    </span>
                </div>

                <div id="totp-feedback" class="hidden text-xs p-3 rounded-lg border"></div>

                <!-- Setup (quando inativo) -->
                <div id="totp-setup-panel" class="<?= !empty($currentUser2faEnabled) ? 'hidden' : '' ?> space-y-4">
                    <div class="flex flex-col sm:flex-row gap-3 items-stretch sm:items-end">
                        <div class="flex-grow">
                            <label for="totp-issuer-input" class="block text-xs font-bold text-gray-400 mb-1">Nome do serviço 2FA (exibido no app autenticador)</label>
                            <input type="text" id="totp-issuer-input" value="<?= esc($currentUser2faIssuer ?? 'DeployHub') ?>" maxlength="40"
                                placeholder="Ex: DeployHub Produção"
                                class="w-full bg-gray-900 border border-gray-700 rounded-lg p-2.5 text-sm text-white focus:border-blue-500 outline-none">
                        </div>
                        <button type="button" onclick="prepare2fa()"
                            class="bg-blue-600 hover:bg-blue-500 text-white text-xs font-bold px-5 py-2.5 rounded-lg transition-all shadow-lg shrink-0">
                            🔑 Gerar chave 2FA
                        </button>
                    </div>

                    <div id="totp-step2" class="hidden bg-gray-950 border border-gray-700 rounded-xl p-4 space-y-4">
                        <div class="flex flex-col md:flex-row gap-6 items-center md:items-start">
                            <div class="shrink-0 text-center">
                                <div class="text-xs font-bold uppercase tracking-wider text-gray-400 mb-2">Escaneie no app</div>
                                <div id="totp-qrcode" class="bg-white p-3 rounded-xl inline-block shadow-lg"></div>
                            </div>
                            <div class="flex-grow w-full space-y-4">
                                <div>
                                    <div class="text-xs font-bold uppercase tracking-wider text-gray-400 mb-1">1. Chave Secreta (Base32)</div>
                                    <div class="flex items-center gap-2">
                                        <code id="totp-secret-display" class="flex-grow bg-gray-900 border border-gray-700 rounded px-3 py-2 text-xs text-green-400 font-mono break-all"></code>
                                        <button type="button" onclick="copyCommandText('totp-secret-display', this)"
                                            class="bg-gray-800 hover:bg-gray-700 text-gray-200 text-xs px-3 py-2 rounded border border-gray-600 shrink-0">
                                            📋 Copiar
                                        </button>
                                    </div>
                                </div>
                                <div>
                                    <div class="text-xs font-bold uppercase tracking-wider text-gray-400 mb-1">URI otpauth:// (opcional)</div>
                                    <div class="flex items-center gap-2">
                                        <code id="totp-uri-display" class="flex-grow bg-gray-900 border border-gray-700 rounded px-3 py-2 text-xs text-blue-300 font-mono truncate"></code>
                                        <button type="button" onclick="copyCommandText('totp-uri-display', this)"
                                            class="bg-gray-800 hover:bg-gray-700 text-gray-200 text-xs px-3 py-2 rounded border border-gray-600 shrink-0">
                                            📋 Copiar
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="flex flex-col sm:flex-row gap-3 items-stretch sm:items-end pt-2 border-t border-gray-800">
                            <div class="flex-grow">
                                <label for="totp-confirm-code" class="block text-xs font-bold text-gray-400 mb-1">2. Digite o código de 6 dígitos gerado no app para confirmar</label>
                                <input type="text" id="totp-confirm-code" inputmode="numeric" maxlength="6" placeholder="000000"
                                    class="w-full sm:w-48 bg-gray-900 border border-gray-700 rounded-lg p-2.5 text-sm text-white font-mono tracking-widest focus:border-green-500 outline-none">
                            </div>
                            <button type="button" onclick="enable2fa()"
                                class="bg-green-600 hover:bg-green-500 text-white text-xs font-bold px-5 py-2.5 rounded-lg transition-all shadow-lg shrink-0">
                                ✅ Confirmar e Ativar 2FA
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Códigos de Recuperação (exibidos uma única vez ao ativar) -->
                <div id="totp-recovery-box" class="hidden bg-yellow-950/40 border border-yellow-500/40 rounded-xl p-4 space-y-2">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-xs font-bold text-yellow-300 uppercase tracking-wider">⚠️ Códigos de Emergência (Uso Único — Salve Agora)</span>
                        <button type="button" onclick="copyCommandText('totp-recovery-list', this)"
                            class="bg-gray-800 hover:bg-gray-700 text-gray-200 text-xs px-3 py-1 rounded border border-gray-600">
                            📋 Copiar códigos
                        </button>
                    </div>
                    <p class="text-xs text-gray-300">Se perder o celular, use qualquer um destes códigos no login (ou para desativar o 2FA). Eles não serão exibidos novamente.</p>
                    <pre id="totp-recovery-list" class="bg-gray-950 border border-gray-700 rounded-lg p-3 text-xs text-yellow-300 font-mono whitespace-pre-wrap"></pre>
                </div>

                <!-- Desativar (quando ativo) -->
                <div id="totp-active-panel" class="<?= !empty($currentUser2faEnabled) ? '' : 'hidden' ?> flex flex-col sm:flex-row gap-3 items-stretch sm:items-end">
                    <div class="flex-grow">
                        <label for="totp-disable-code" class="block text-xs font-bold text-gray-400 mb-1">Para desativar o 2FA, informe o código TOTP (6 dígitos) ou um código de emergência</label>
                        <input type="text" id="totp-disable-code" maxlength="16" placeholder="000000 ou XXXX-XXXX"
                            class="w-full sm:w-56 bg-gray-900 border border-gray-700 rounded-lg p-2.5 text-sm text-white font-mono tracking-widest focus:border-red-500 outline-none">
                    </div>
                    <button type="button" onclick="disable2fa()"
                        class="bg-red-900/60 hover:bg-red-700 text-red-200 border border-red-500/40 text-xs font-bold px-5 py-2.5 rounded-lg transition-all shrink-0">
                        Desativar 2FA
                    </button>
                </div>
            </div>

            <!-- Bloco Completo Plug-and-Play para Hardening -->
            <div class="bg-gray-800 rounded-2xl p-6 border border-gray-700 shadow-xl">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-3">
                    <div>
                        <h3 class="text-base font-bold text-blue-400">⚡ Script Completo de Isolamento (Opção 2: deployer + sudoers)</h3>
                        <p class="text-xs text-gray-400 mt-0.5">Cria o usuário <code class="text-gray-300">deployer</code> sem shell interativo, protege o script e restringe o <code class="text-gray-300">sudoers</code> exclusivamente a ele.</p>
                    </div>
                    <button onclick="copyCommandText('full-hardening-cmd', this)"
                        class="shrink-0 bg-gray-900 hover:bg-gray-700 text-gray-200 text-xs font-bold px-4 py-2 rounded-lg border border-gray-600 transition-all">
                        📋 Copiar Tudo
                    </button>
                </div>
                <pre id="full-hardening-cmd" class="bg-gray-950 border border-gray-700 rounded-xl p-4 text-xs text-green-400 font-mono whitespace-pre-wrap overflow-x-auto"><?= esc($audit['full_fix_cmd'] ?? '') ?></pre>
            </div>
        </div>
    </div>

    <!-- Modal Adicionar Servidor -->
    <div id="add-server-modal" class="fixed inset-0 bg-black/50 backdrop-blur-sm hidden flex items-center justify-center z-50">
        <div class="bg-gray-800 rounded-2xl p-8 border border-gray-700 shadow-2xl w-full max-w-md">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-xl font-bold text-white flex items-center gap-2">🖥️ Novo Servidor</h3>
                <button onclick="document.getElementById('add-server-modal').classList.add('hidden')" class="text-gray-400 hover:text-white transition-colors text-xl">&times;</button>
            </div>
            <form action="/admin/action" method="POST" class="space-y-4">
                <input type="hidden" name="action" value="add_server">
                <input type="hidden" name="tab" value="servidores">
                
                <div>
                    <label class="block text-xs font-bold text-gray-400 mb-1">NOME DE IDENTIFICAÇÃO</label>
                    <input type="text" name="name" placeholder="Ex: Banco de Dados Vitalog" required class="w-full bg-gray-900 border border-gray-700 rounded-lg p-2 text-sm text-white focus:border-blue-500 outline-none transition-all">
                </div>
                
                <div>
                    <label class="block text-xs font-bold text-gray-400 mb-1">IP OU HOSTNAME (SSH HOST)</label>
                    <input type="text" name="ssh_host" placeholder="Ex: 192.168.1.100" required class="w-full bg-gray-900 border border-gray-700 rounded-lg p-2 text-sm text-white focus:border-blue-500 outline-none transition-all">
                </div>
                
                <div>
                    <label class="block text-xs font-bold text-gray-400 mb-1">USUÁRIO SSH</label>
                    <input type="text" name="ssh_user" value="root" required class="w-full bg-gray-900 border border-gray-700 rounded-lg p-2 text-sm text-white focus:border-blue-500 outline-none transition-all">
                </div>
                
                <div>
                    <label class="block text-xs font-bold text-gray-400 mb-1">CAMINHO DA CHAVE SSH (OPCIONAL)</label>
                    <input type="text" name="ssh_key" placeholder="Ex: ~/.ssh/id_do_server_api" class="w-full bg-gray-900 border border-gray-700 rounded-lg p-2 text-sm text-white focus:border-blue-500 outline-none transition-all">
                    <p class="text-[10px] text-gray-500 mt-1">*A chave SSH da máquina local já deve estar autorizada no servidor remoto.</p>
                </div>
                
                <button type="submit" class="w-full bg-blue-600 hover:bg-blue-500 p-3 rounded-lg text-sm font-bold transition-all shadow-lg text-white mt-6">Adicionar ao Monitoramento</button>
            </form>
        </div>
    </div>


    <style>
        .custom-scrollbar::-webkit-scrollbar {
            width: 8px;
        }

        .custom-scrollbar::-webkit-scrollbar-track {
            background: #1f2937;
        }

        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: #374151;
            border-radius: 4px;
        }

        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background: #4b5563;
        }
    </style>
    <script>
        function switchTab(tabId) {
            const validTabs = ['logs', 'comandos', 'configs', 'servidores', 'seguranca'];
            if (!validTabs.includes(tabId)) {
                tabId = 'logs';
            }

            // Esconde todas as abas e reseta estilo dos botoes
            validTabs.forEach(id => {
                const content = document.getElementById('tab-content-' + id);
                if (content) content.classList.add('hidden');
                const btn = document.getElementById('tab-btn-' + id);
                if (btn) {
                    btn.classList.remove('text-white');
                    btn.classList.add('text-gray-500');
                }
            });

            // Ativa a aba selecionada
            const activeContent = document.getElementById('tab-content-' + tabId);
            const activeBtn = document.getElementById('tab-btn-' + tabId);
            if (activeContent) activeContent.classList.remove('hidden');
            if (activeBtn) {
                activeBtn.classList.remove('text-gray-500');
                activeBtn.classList.add('text-white');
            }

            // Atualiza a URL sem recarregar para persistir o estado
            const url = new URL(window.location);
            url.searchParams.set('tab', tabId);
            window.history.pushState({}, '', url);

            if (tabId === 'servidores') {
                fetchStats();
            }
        }

        function toggleFixBox(boxId) {
            const box = document.getElementById(boxId);
            if (box) {
                box.classList.toggle('hidden');
            }
        }

        function showTotpFeedback(message, isError = false) {
            const el = document.getElementById('totp-feedback');
            if (!el) return;
            el.textContent = message;
            el.className = 'text-xs p-3 rounded-lg border ' + (isError
                ? 'bg-red-900/40 border-red-500/50 text-red-200'
                : 'bg-green-900/40 border-green-500/50 text-green-200');
            el.classList.remove('hidden');
        }

        async function prepare2fa() {
            const issuerInput = document.getElementById('totp-issuer-input');
            const issuer = issuerInput ? issuerInput.value.trim() : 'DeployHub';
            const body = new URLSearchParams({ issuer });

            try {
                const res = await fetch('/admin/2fa/prepare', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || 'Erro ao gerar chave 2FA.');

                document.getElementById('totp-secret-display').textContent = data.secret;
                document.getElementById('totp-uri-display').textContent = data.uri;
                const qrEl = document.getElementById('totp-qrcode');
                if (qrEl && typeof QRCode !== 'undefined') {
                    qrEl.replaceChildren();
                    new QRCode(qrEl, {
                        text: data.uri,
                        width: 176,
                        height: 176,
                        colorDark: '#000000',
                        colorLight: '#ffffff',
                        correctLevel: QRCode.CorrectLevel.M
                    });
                }
                document.getElementById('totp-step2').classList.remove('hidden');
                showTotpFeedback(`Chave gerada para "${data.issuer}". Adicione no app autenticador e confirme com o código de 6 dígitos.`);
            } catch (err) {
                showTotpFeedback(err.message, true);
            }
        }

        async function enable2fa() {
            const code = (document.getElementById('totp-confirm-code')?.value || '').trim();
            const body = new URLSearchParams({ code });

            try {
                const res = await fetch('/admin/2fa/enable', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body
                });
                const data = await res.json();
                if (!res.ok || !data.ok) throw new Error(data.error || 'Código inválido.');

                document.getElementById('totp-setup-panel').classList.add('hidden');
                document.getElementById('totp-active-panel').classList.remove('hidden');
                if (Array.isArray(data.recovery_codes) && data.recovery_codes.length > 0) {
                    const recBox = document.getElementById('totp-recovery-box');
                    const recList = document.getElementById('totp-recovery-list');
                    if (recBox && recList) {
                        recList.textContent = data.recovery_codes.join('\n');
                        recBox.classList.remove('hidden');
                    }
                }
                const badge = document.getElementById('totp-status-badge');
                if (badge) {
                    badge.textContent = `🟢 2FA Ativo (${data.issuer})`;
                    badge.className = 'text-xs font-bold px-3 py-1 rounded-full border bg-green-900/50 text-green-300 border-green-500/30';
                }
                showTotpFeedback('2FA ativado com sucesso! O próximo login exigirá o código TOTP.');
                refreshSecurityAudit();
            } catch (err) {
                showTotpFeedback(err.message, true);
            }
        }

        async function disable2fa() {
            const code = (document.getElementById('totp-disable-code')?.value || '').trim();
            const body = new URLSearchParams({ code });

            try {
                const res = await fetch('/admin/2fa/disable', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body
                });
                const data = await res.json();
                if (!res.ok || !data.ok) throw new Error(data.error || 'Não foi possível desativar o 2FA.');

                document.getElementById('totp-active-panel').classList.add('hidden');
                document.getElementById('totp-setup-panel').classList.remove('hidden');
                document.getElementById('totp-step2').classList.add('hidden');
                const badge = document.getElementById('totp-status-badge');
                if (badge) {
                    badge.textContent = '🟡 2FA Inativo';
                    badge.className = 'text-xs font-bold px-3 py-1 rounded-full border bg-yellow-900/50 text-yellow-300 border-yellow-500/30';
                }
                showTotpFeedback('2FA desativado para este usuário.');
                refreshSecurityAudit();
            } catch (err) {
                showTotpFeedback(err.message, true);
            }
        }

        function copyCommandText(elementId, btnEl) {
            const el = document.getElementById(elementId);
            if (!el) return;
            navigator.clipboard.writeText(el.textContent).then(() => {
                if (btnEl) {
                    const original = btnEl.textContent;
                    btnEl.textContent = '✅ Copiado!';
                    setTimeout(() => { btnEl.textContent = original; }, 2000);
                }
            }).catch(err => {
                console.error('Erro ao copiar comando: ', err);
                alert('Não foi possível copiar o comando.');
            });
        }

        async function refreshSecurityAudit() {
            const icon = document.getElementById('audit-refresh-icon');
            if (icon) icon.classList.add('animate-spin');

            try {
                const response = await fetch('/admin/security-audit');
                if (!response.ok) throw new Error('Falha HTTP ' + response.status);
                const audit = await response.json();

                const titleEl = document.getElementById('audit-level-title');
                const checkedEl = document.getElementById('audit-checked-at');
                const scoreEl = document.getElementById('audit-score-text');
                const barEl = document.getElementById('audit-score-bar');
                const pillEl = document.getElementById('security-tab-pill');
                const fullCmdEl = document.getElementById('full-hardening-cmd');
                const listEl = document.getElementById('security-checks-list');

                if (titleEl) titleEl.textContent = audit.level || '';
                if (checkedEl) checkedEl.textContent = audit.checked_at || '';
                if (scoreEl) scoreEl.textContent = `${audit.passed}/${audit.total}`;
                if (pillEl) pillEl.textContent = `${audit.passed}/${audit.total}`;
                if (fullCmdEl) fullCmdEl.textContent = audit.full_fix_cmd || '';
                if (barEl) {
                    barEl.style.width = `${audit.score_pct || 0}%`;
                    barEl.className = 'h-2.5 rounded-full transition-all duration-500 ' + (
                        audit.level_status === 'ok' ? 'bg-green-500' :
                        audit.level_status === 'danger' ? 'bg-red-500' : 'bg-yellow-500'
                    );
                }

                if (listEl && Array.isArray(audit.checks)) {
                    listEl.replaceChildren();
                    audit.checks.forEach(check => {
                        const status = check.status || 'warn';
                        const cardBorder = status === 'ok' ? 'border-green-500/30' : (status === 'danger' ? 'border-red-500/40' : 'border-yellow-500/30');
                        const badgeStyle = status === 'ok'
                            ? 'bg-green-900/50 text-green-300 border-green-500/30'
                            : (status === 'danger' ? 'bg-red-900/50 text-red-300 border-red-500/40' : 'bg-yellow-900/50 text-yellow-300 border-yellow-500/30');
                        const safeId = String(check.id || 'check').replace(/[^a-zA-Z0-9_-]/g, '');

                        const card = document.createElement('div');
                        card.className = `bg-gray-800 rounded-2xl p-5 border ${cardBorder} shadow-lg`;

                        const row = document.createElement('div');
                        row.className = 'flex flex-col md:flex-row md:items-start justify-between gap-4';

                        const info = document.createElement('div');
                        info.className = 'space-y-1';

                        const header = document.createElement('div');
                        header.className = 'flex flex-wrap items-center gap-3';

                        const h3 = document.createElement('h3');
                        h3.className = 'text-base font-bold text-white';
                        h3.textContent = check.title || '';

                        const badge = document.createElement('span');
                        badge.className = `text-xs font-bold px-2.5 py-1 rounded-full border ${badgeStyle}`;
                        badge.textContent = check.badge || '';

                        header.appendChild(h3);
                        header.appendChild(badge);

                        const desc = document.createElement('p');
                        desc.className = 'text-xs text-gray-400';
                        desc.textContent = check.description || '';

                        const details = document.createElement('p');
                        details.className = 'text-sm text-gray-200 pt-1';
                        details.textContent = check.details || '';

                        info.appendChild(header);
                        info.appendChild(desc);
                        info.appendChild(details);
                        row.appendChild(info);

                        if (check.fix_command) {
                            const fixBtn = document.createElement('button');
                            fixBtn.type = 'button';
                            fixBtn.className = 'shrink-0 bg-gray-900 hover:bg-gray-700 text-yellow-300 border border-yellow-500/40 text-xs font-bold px-4 py-2 rounded-lg transition-all';
                            fixBtn.textContent = '🛠️ Corrigir agora';
                            fixBtn.addEventListener('click', () => toggleFixBox('fix-box-' + safeId));
                            row.appendChild(fixBtn);
                        }

                        card.appendChild(row);

                        if (check.fix_command) {
                            const fixBox = document.createElement('div');
                            fixBox.id = 'fix-box-' + safeId;
                            fixBox.className = 'hidden mt-4 bg-gray-950 border border-gray-700 rounded-xl p-4';

                            const fixBar = document.createElement('div');
                            fixBar.className = 'flex items-center justify-between mb-2';

                            const fixLabel = document.createElement('span');
                            fixLabel.className = 'text-xs font-bold uppercase tracking-wider text-gray-400';
                            fixLabel.textContent = 'Cole no terminal SSH da VPS:';

                            const copyBtn = document.createElement('button');
                            copyBtn.type = 'button';
                            copyBtn.className = 'bg-gray-800 hover:bg-gray-700 text-gray-200 text-xs px-3 py-1 rounded border border-gray-600 transition-all';
                            copyBtn.textContent = '📋 Copiar comando';
                            copyBtn.addEventListener('click', () => copyCommandText('fix-cmd-' + safeId, copyBtn));

                            fixBar.appendChild(fixLabel);
                            fixBar.appendChild(copyBtn);

                            const pre = document.createElement('pre');
                            pre.id = 'fix-cmd-' + safeId;
                            pre.className = 'text-xs text-green-400 font-mono whitespace-pre-wrap overflow-x-auto';
                            pre.textContent = check.fix_command;

                            fixBox.appendChild(fixBar);
                            fixBox.appendChild(pre);
                            card.appendChild(fixBox);
                        }

                        listEl.appendChild(card);
                    });
                }
            } catch (err) {
                console.error('Erro ao atualizar diagnóstico de segurança:', err);
            } finally {
                if (icon) icon.classList.remove('animate-spin');
            }
        }

        // Inicializa a aba correta
        document.addEventListener('DOMContentLoaded', () => {
            const params = new URLSearchParams(window.location.search);
            const currentTab = params.get('tab') || 'logs';
            switchTab(currentTab);
        });

        function copyLog() {
            const logContent = document.getElementById('log-content').innerText;
            navigator.clipboard.writeText(logContent).then(() => {
                alert('Log copiado para a área de transferência!');
            }).catch(err => {
                console.error('Erro ao copiar: ', err);
                alert('Não foi possível copiar o log.');
            });
        }

        function showCommand(cmdId) {
            // Hide all cmd-sections
            document.querySelectorAll('.cmd-section').forEach(el => el.classList.add('hidden'));

            // Reset buttons
            document.querySelectorAll('[id^="btn-cmd-"]').forEach(btn => {
                btn.classList.remove('bg-blue-600', 'text-white', 'shadow-lg');
                btn.classList.add('bg-gray-800', 'text-gray-400');
            });

            // Show selected
            document.getElementById(cmdId).classList.remove('hidden');

            // Highlight button
            const btn = document.getElementById('btn-' + cmdId);
            btn.classList.remove('bg-gray-800', 'text-gray-400');
            btn.classList.add('bg-blue-600', 'text-white', 'shadow-lg');
        }
    </script>


    <script src="/js/qrcode.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/tinymce/7.2.1/tinymce.min.js" referrerpolicy="origin"></script>
    <script>
        function toggleCheatsheetEdit() {
            const viewDiv = document.getElementById('cheatsheet-view');
            const editDiv = document.getElementById('cheatsheet-edit');
            if (viewDiv.classList.contains('hidden')) {
                viewDiv.classList.remove('hidden');
                editDiv.classList.add('hidden');
                tinymce.remove('#cheatsheet-editor');
            } else {
                viewDiv.classList.add('hidden');
                editDiv.classList.remove('hidden');
                tinymce.init({
                    selector: '#cheatsheet-editor',
                    plugins: 'lists link code table',
                    toolbar: 'undo redo | styles | bold italic | alignleft aligncenter alignright alignjustify | outdent indent | link code',
                    skin: 'oxide-dark',
                    content_css: 'dark',
                    height: 500,
                    promotion: false,
                    branding: false
                });
            }
        }

        let statsInterval = null;
        async function fetchStats() {
            const icon = document.getElementById('stats-refresh-icon');
            if (icon) icon.classList.add('animate-spin');
            
            try {
                const response = await fetch('/admin/stats');
                const data = await response.json();
                
                const grid = document.getElementById('servers-grid');
                grid.innerHTML = ''; // Limpar antes de desenhar
                
                if (data.servers && data.servers.length > 0) {
                    data.servers.forEach(server => {
                        const card = document.createElement('div');
                        card.className = 'bg-gray-800 rounded-2xl p-6 border border-gray-700 shadow-xl';
                        
                        let errorMsg = '';
                        if (server.error) {
                            errorMsg = `<div class="mt-4 p-3 bg-red-900/30 border border-red-500/30 rounded text-red-400 text-sm">${server.error}</div>`;
                        }

                        let deleteBtn = '';
                        if (server.name !== 'Servidor Local (Principal)') {
                            deleteBtn = `
                            <form action="/admin/action" method="POST" class="inline" onsubmit="return confirm('Tem certeza que deseja parar de monitorar este servidor?');">
                                <input type="hidden" name="action" value="delete_server">
                                <input type="hidden" name="tab" value="servidores">
                                <input type="hidden" name="ssh_host" value="${server.ssh_host || server.name}">
                                <button type="submit" class="text-gray-500 hover:text-red-400 transition-colors" title="Remover Servidor">🗑️</button>
                            </form>`;
                        }

                        card.innerHTML = `
                            <div class="flex justify-between items-start mb-6">
                                <h3 class="text-lg font-bold text-blue-400 flex items-center gap-2">🖥️ ${server.name}</h3>
                                ${deleteBtn}
                            </div>
                            
                            <div class="space-y-6">
                                <!-- CPU -->
                                <div>
                                    <div class="flex justify-between text-sm mb-2">
                                        <span class="text-gray-400 font-bold uppercase tracking-wider">Processador (CPU)</span>
                                        <span class="text-white font-mono">${server.cpu}%</span>
                                    </div>
                                    <div class="w-full bg-gray-900 rounded-full h-2.5">
                                        <div class="bg-blue-500 h-2.5 rounded-full" style="width: ${server.cpu}%"></div>
                                    </div>
                                </div>
                                
                                <!-- RAM -->
                                <div>
                                    <div class="flex justify-between text-sm mb-2">
                                        <span class="text-gray-400 font-bold uppercase tracking-wider">Memória (RAM)</span>
                                        <span class="text-white font-mono">${server.memory.percent}%</span>
                                    </div>
                                    <div class="text-xs text-gray-500 mb-2">${server.memory.used} GB / ${server.memory.total} GB</div>
                                    <div class="w-full bg-gray-900 rounded-full h-2.5">
                                        <div class="bg-purple-500 h-2.5 rounded-full" style="width: ${server.memory.percent}%"></div>
                                    </div>
                                </div>
                                
                                <!-- Disco -->
                                <div>
                                    <div class="flex justify-between text-sm mb-2">
                                        <span class="text-gray-400 font-bold uppercase tracking-wider">Disco (HD)</span>
                                        <span class="text-white font-mono">${server.disk.percent}%</span>
                                    </div>
                                    <div class="text-xs text-gray-500 mb-2">${server.disk.used} GB / ${server.disk.total} GB</div>
                                    <div class="w-full bg-gray-900 rounded-full h-2.5">
                                        <div class="bg-green-500 h-2.5 rounded-full" style="width: ${server.disk.percent}%"></div>
                                    </div>
                                </div>
                            </div>
                            ${errorMsg}
                        `;
                        grid.appendChild(card);
                    });
                } else {
                    grid.innerHTML = '<div class="col-span-full text-center py-12 text-gray-500">Nenhum servidor encontrado.</div>';
                }
            } catch (err) {
                console.error("Erro ao buscar stats: ", err);
            } finally {
                if (icon) icon.classList.remove('animate-spin');
            }

            // Atualizar a cada 10 segundos
            if (statsInterval) clearInterval(statsInterval);
            statsInterval = setInterval(fetchStats, 10000);
        }
    </script>
</body>

</html>