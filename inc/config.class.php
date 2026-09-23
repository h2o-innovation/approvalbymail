<?php

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/**
 * Configuração do plugin: feature flags + utilitários de cripto.
 */
class PluginApprovalbymailConfig extends CommonDBTM
{
    /** Feature flags (IDs fixos na tabela de config). */
    public const TICKET_VALIDATION = 1;
    public const TICKET_SOLUTION   = 2;
    public const FOLLOWUP_PRIVATE  = 3; // is_active=1 => acompanhamento de auditoria privado
    public const LOGO             = 4; // URL da logo exibida na página de aprovação
    public const SHOW_DESCRIPTION = 5; // is_active=1 => exibe a descrição do chamado na aprovação

    public $dohistory = false;

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_approvalbymail_config';
    }

    public static function getTypeName($nb = 0)
    {
        return __('Approval by Mail', 'approvalbymail');
    }

    public static function canView(): bool
    {
        return Session::haveRight('config', READ);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canUpdate(): bool
    {
        return Session::haveRight('config', UPDATE);
    }

    public static function canDelete(): bool
    {
        return false;
    }

    public static function canPurge(): bool
    {
        return false;
    }

    /**
     * Um feature flag está ativo? (lê is_active da linha pelo id).
     * Reutilizável: portão de cada tipo de aprovação e da privacidade do followup.
     */
    public static function isFeatureActive(int $id): bool
    {
        /** @var DBmysql $DB */
        global $DB;

        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => ['id' => $id]]) as $row) {
            return ((int) $row['is_active']) === 1;
        }
        return false;
    }

    // ---- Aba dentro de Config (Configurar > Geral) ----

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$withtemplate && $item instanceof Config) {
            return self::getTypeName();
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof Config) {
            self::showConfigForm();
        }
        return true;
    }

    public static function showConfigForm(): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $action = Plugin::getWebDir('approvalbymail') . '/front/config.form.php';

        echo '<form name="approvalbymail_config" method="post" action="' . htmlspecialchars($action) . '">';
        echo '<table class="tab_cadre_fixe">';
        echo '<tr><th colspan="3">' . self::getTypeName() . '</th></tr>';
        echo '<tr class="tab_bg_1">';
        echo '<th>' . __('Action', 'glpi') . '</th>';
        echo '<th>' . __('Description', 'glpi') . '</th>';
        echo '<th>' . __('Active', 'glpi') . '</th>';
        echo '</tr>';

        foreach ($DB->request(['FROM' => self::getTable(), 'ORDER' => 'id']) as $row) {
            $id = (int) $row['id'];
            if ($id === self::LOGO) {
                continue; // renderizado separadamente
            }
            echo '<tr class="tab_bg_1">';
            echo '<td>' . htmlspecialchars((string) $row['name']) . '</td>';
            echo '<td>' . htmlspecialchars((string) ($row['content'] ?? '')) . '</td>';
            echo '<td>';
            Dropdown::showYesNo('is_active_' . $id, (int) $row['is_active']);
            echo '</td>';
            echo '</tr>';
        }

        // --- Logo URL ---
        $logo_row = current(iterator_to_array($DB->request([
            'FROM' => self::getTable(),
            'WHERE' => ['id' => self::LOGO],
        ])));
        $logo_url = $logo_row ? (string) ($logo_row['content'] ?? '') : '';
        echo '<tr class="tab_bg_1">';
        echo '<td>' . __('Logo URL', 'approvalbymail') . '</td>';
        echo '<td colspan="2">';
        echo '<input type="text" name="logo_url" value="' . htmlspecialchars($logo_url, ENT_QUOTES, 'UTF-8')
            . '" style="width:100%" placeholder="https://example.com/logo.png">';
        echo '</td>';
        echo '</tr>';

        echo '<tr class="tab_bg_2"><td colspan="3" class="center">';
        echo Html::submit(_x('button', 'Save'), [
            'name'  => 'update_config',
            'class' => 'btn btn-primary',
        ]);
        echo '</td></tr>';
        echo '</table>';
        Html::closeForm();
    }

    // ---- Cripto (Padrão SDB-1): chave gerenciada pelo GLPI ----

    public static function encrypt(string $plaintext): string
    {
        return (new GLPIKey())->encrypt($plaintext);
    }

    public static function decrypt(string $ciphertext): ?string
    {
        try {
            return (new GLPIKey())->decrypt(str_replace(' ', '+', $ciphertext));
        } catch (\Throwable $e) {
            return null;
        }
    }
}
