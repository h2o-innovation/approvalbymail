<?php

/**
 * approval by mail — ação por e-mail no GLPI.
 * Fork modernizado (Padrão SDB) do plugin "SDB - Ação por e-mail" (GPLv3).
 */

define('PLUGIN_APPROVALBYMAIL_VERSION', '0.4.0-dev');
define('PLUGIN_APPROVALBYMAIL_MIN_GLPI', '11.0.0');
define('PLUGIN_APPROVALBYMAIL_MAX_GLPI', '11.0.99');

/**
 * Inicialização do plugin (chamada em todo carregamento do GLPI).
 */
function plugin_init_approvalbymail(): void
{
    /** @var array $PLUGIN_HOOKS */
    global $PLUGIN_HOOKS;

    // Conformidade CSRF dos formulários do plugin.
    $PLUGIN_HOOKS['csrf_compliant']['approvalbymail'] = true;

    $plugin = new Plugin();
    if (!$plugin->isInstalled('approvalbymail') || !$plugin->isActivated('approvalbymail')) {
        return;
    }

    // Página pública de aprovação: não exige sessão.
    // (GLPI 11 firewall: sem isso, o Symfony redireciona para login.)
    \Glpi\Http\SessionManager::registerPluginStatelessPath(
        'approvalbymail',
        '~^/front/approve\.php~'
    );

    // Aba de configuração dentro de "Configurar > Geral".
    Plugin::registerClass(PluginApprovalbymailConfig::class, [
        'addtabon' => Config::class,
    ]);

    // A ação tokenizada suporta modelos de notificação (S2).
    Plugin::registerClass(PluginApprovalbymailAction::class, [
        'notificationtemplates_types' => true,
    ]);

    // Link de engrenagem na lista de plugins.
    $PLUGIN_HOOKS['config_page']['approvalbymail'] = 'front/config.php';

    // Gatilhos de criação:
    //  - S1: pedido de validação de chamado  -> notifica o validador
    //  - S3: solução de chamado (WAITING)     -> notifica o requerente
    $PLUGIN_HOOKS['item_add']['approvalbymail'] = [
        TicketValidation::class => [PluginApprovalbymailTicketValidation::class, 'item_add'],
        ITILSolution::class     => [PluginApprovalbymailItilSolution::class, 'item_add'],
    ];
}

/**
 * Metadados do plugin.
 */
function plugin_version_approvalbymail(): array
{
    return [
        'name'           => 'Approval by Mail',
        'version'        => PLUGIN_APPROVALBYMAIL_VERSION,
        'author'         => 'Carlos Alberto Correa Filho - IPT.br',
        'license'        => 'GPLv3',
        'homepage'       => '',
        'requirements'   => [
            'glpi' => [
                'min' => PLUGIN_APPROVALBYMAIL_MIN_GLPI,
                'max' => PLUGIN_APPROVALBYMAIL_MAX_GLPI,
            ],
        ],
    ];
}

/**
 * Pré-requisitos (a faixa de versão do GLPI já é validada via requirements).
 */
function plugin_approvalbymail_check_prerequisites(): bool
{
    return true;
}

/**
 * Verificação de configuração mínima para ativar.
 */
function plugin_approvalbymail_check_config($verbose = false): bool
{
    return true;
}
