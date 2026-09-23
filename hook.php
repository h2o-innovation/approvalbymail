<?php

/**
 * Instalação/desinstalação do plugin approval by mail.
 * Padrão SDB: simétrico, idempotente, sem SQL com input concatenado.
 */

function plugin_approvalbymail_install(): bool
{
    /** @var DBmysql $DB */
    global $DB;

    $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');

    // --- Tabela de configuração (feature flags) ---
    $config_table = PluginApprovalbymailConfig::getTable();
    if (!$DB->tableExists($config_table)) {
        $DB->doQuery(
            "CREATE TABLE IF NOT EXISTS `$config_table` (
                `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name`      VARCHAR(100) NOT NULL,
                `content`   VARCHAR(255) NULL DEFAULT NULL,
                `is_active` TINYINT(1) NOT NULL DEFAULT '0',
                `date_mod`  TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    // Seeds + migração idempotente: garante cada linha de configuração sem
    // sobrescrever escolhas já feitas pelo admin. validação (ON), solução
    // (OFF — opt-in), followup privado (ON), logo (ON), descrição (ON).
    foreach ([
        PluginApprovalbymailConfig::TICKET_VALIDATION => [
            'Ticket - Aprovação de Validação',
            'Envia e-mail para aprovar/recusar a validação de chamado',
            1,
        ],
        PluginApprovalbymailConfig::TICKET_SOLUTION => [
            'Ticket - Aprovação de Solução',
            'Envia e-mail para o requerente aprovar/recusar a solução do chamado',
            0,
        ],
        PluginApprovalbymailConfig::FOLLOWUP_PRIVATE => [
            'Acompanhamento de auditoria — privado',
            'Sim = acompanhamento privado (só técnicos); Não = público',
            1,
        ],
        PluginApprovalbymailConfig::LOGO => [
            'URL da Logo',
            '',
            1,
        ],
        PluginApprovalbymailConfig::SHOW_DESCRIPTION => [
            'Mostrar descrição do chamado',
            'Exibe a descrição do chamado na página e no e-mail de aprovação',
            1,
        ],
    ] as $config_id => [$name, $content, $is_active]) {
        $row_exists = false;
        foreach ($DB->request(['FROM' => $config_table, 'WHERE' => ['id' => $config_id]]) as $_row) {
            $row_exists = true;
        }
        if (!$row_exists) {
            $DB->insert($config_table, [
                'id'        => $config_id,
                'name'      => $name,
                'content'   => $content,
                'is_active' => $is_active,
                'date_mod'  => $now,
            ]);
        }
    }

    // --- Tabela de ações tokenizadas ---
    $action_table = PluginApprovalbymailAction::getTable();
    if (!$DB->tableExists($action_table)) {
        $userfk = User::getForeignKeyField();
        $DB->doQuery(
            "CREATE TABLE IF NOT EXISTS `$action_table` (
                `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `$userfk`       INT UNSIGNED NOT NULL DEFAULT '0',
                `items_id`      INT UNSIGNED NOT NULL DEFAULT '0',
                `itemtype`      VARCHAR(100) NOT NULL,
                `token`         VARCHAR(128) NOT NULL,
                `used_at`       TIMESTAMP NULL DEFAULT NULL,
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `itemtype_items_id` (`itemtype`, `items_id`),
                KEY `token` (`token`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    // --- Modelos de notificação (S2) ---
    if (!PluginApprovalbymailNotification::installNotificationModels()) {
        return false;
    }

    return true;
}

/**
 * Migração em atualização de versão. Reexecuta a instalação idempotente para
 * criar linhas de configuração novas e recriar os modelos de notificação
 * (o template ganha o bloco de descrição). Atenção: recriação do modelo
 * descarta customizações feitas no template pelo admin.
 */
function plugin_approvalbymail_upgrade(string $old_version): bool
{
    return plugin_approvalbymail_install();
}

function plugin_approvalbymail_uninstall(): bool
{
    /** @var DBmysql $DB */
    global $DB;

    PluginApprovalbymailNotification::uninstallNotificationModels();

    foreach ([
        PluginApprovalbymailAction::getTable(),
        PluginApprovalbymailConfig::getTable(),
    ] as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `$table`");
        }
    }

    return true;
}
