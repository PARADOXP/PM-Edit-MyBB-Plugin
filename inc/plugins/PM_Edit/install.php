<?php
if(!defined('IN_MYBB')) { die('Direct initialization of this file is not allowed.'); }

if (!defined("PLUGINLIBRARY")) {
    define("PLUGINLIBRARY", MYBB_ROOT . 'inc/plugins/pluginlibrary.php');
}

function pm_edit_install_run()
{
    global $db;

    // Load and execute schema from database.sql
    $schemaFile = MYBB_ROOT.'inc/plugins/PM_Edit/database.sql';
    if(file_exists($schemaFile))
    {
        $sql = @file_get_contents($schemaFile);
        if($sql !== false)
        {
            // Replace {TABLE_PREFIX} placeholder
            $sql = str_replace('{TABLE_PREFIX}', $db->table_prefix, $sql);

            // Split by semicolon taking into account simple cases
            $statements = array_filter(array_map('trim', explode(';', $sql)));
            foreach($statements as $statement)
            {
                if($statement !== '') {
                    $db->write_query($statement);
                }
            }
        }
    }

    // Check existing columns and add only if they don't exist
    $columns = $db->show_fields_from('privatemessages');
    $existing_columns = array();
    foreach($columns as $column)
    {
        $existing_columns[] = $column['Field'];
    }

    // Add edit tracking columns if they don't exist
    if(!in_array('edituid', $existing_columns))
    {
        $db->write_query("ALTER TABLE {$db->table_prefix}privatemessages ADD COLUMN edituid INT UNSIGNED NOT NULL DEFAULT 0");
    }
    if(!in_array('edittime', $existing_columns))
    {
        $db->write_query("ALTER TABLE {$db->table_prefix}privatemessages ADD COLUMN edittime INT UNSIGNED NOT NULL DEFAULT 0");
    }
    if(!in_array('editreason', $existing_columns))
    {
        $db->write_query("ALTER TABLE {$db->table_prefix}privatemessages ADD COLUMN editreason VARCHAR(150) NOT NULL DEFAULT ''");
    }
    if(!in_array('pm_conversation_key', $existing_columns))
    {
        $db->write_query("ALTER TABLE {$db->table_prefix}privatemessages ADD COLUMN pm_conversation_key VARCHAR(32) NOT NULL DEFAULT ''");
        $db->write_query("ALTER TABLE {$db->table_prefix}privatemessages ADD INDEX pm_conversation_key (pm_conversation_key)");
    }

    // Install settings via PluginLibrary from settings.json if available
    if(file_exists(PLUGINLIBRARY))
    {
        require_once PLUGINLIBRARY;
        $PL = new PluginLibrary();
        $settingsFile = MYBB_ROOT.'inc/plugins/PM_Edit/settings.json';
        if(file_exists($settingsFile))
        {
            $json = @file_get_contents($settingsFile);
            $data = @json_decode($json, true);
            if(is_array($data) && isset($data['group']) && isset($data['settings']) && is_array($data['settings']))
            {
                $group = $data['group'];
                $name = !empty($group['name']) ? $group['name'] : 'pm_edit';
                $title = !empty($group['title']) ? $group['title'] : 'PM Edit Settings';
                $description = !empty($group['description']) ? $group['description'] : '';
                $PL->settings($name, $title, $description, $data['settings']);
            }
        }
    }
}

function pm_edit_activate_run()
{
    global $db;

    // Rebuild settings
    if(function_exists('rebuild_settings') === false) {
        require_once MYBB_ROOT.'inc/functions.php';
    }
    rebuild_settings();

    // Install CSS stylesheet via PluginLibrary
    if(file_exists(PLUGINLIBRARY))
    {
        require_once PLUGINLIBRARY;
        $PL = new PluginLibrary();
        $css_path = MYBB_ROOT.'inc/plugins/PM_Edit/Assets/pm_edit.css';
        if(file_exists($css_path))
        {
            $css = @file_get_contents($css_path);
            if($css !== false)
            {
                $PL->stylesheet('pm_edit', $css);
            }
        }
    }

    // Install templates
    pm_edit_install_templates();

    // Add template modifications
    require_once MYBB_ROOT."inc/adminfunctions_templates.php";

    // Add PM edit button and history link to postbit templates (for PMs)
    // Both button and history are added after delete button in one replace
    find_replace_templatesets("postbit", "#" . preg_quote('{$post[\'button_delete_pm\']}') . "#i", '{$post[\'button_delete_pm\']}{$post[\'button_edit_pm\']}{$post[\'pm_edit_history\']}');
    find_replace_templatesets("postbit_classic", "#" . preg_quote('{$post[\'button_delete_pm\']}') . "#i", '{$post[\'button_delete_pm\']}{$post[\'button_edit_pm\']}{$post[\'pm_edit_history\']}');

    // Add edit notice to private_read template (after message)
    find_replace_templatesets("private_read", "#" . preg_quote('{$message}') . "#i", '{$message}{$pm[\'edit_notice\']}');

    // Add task for pruning logs
    pm_edit_add_task();
}

function pm_edit_install_templates()
{
    global $db, $PL;

    // Use PluginLibrary to add templates (same as Reactions plugin)
    if(file_exists(PLUGINLIBRARY))
    {
        require_once PLUGINLIBRARY;
        $PL = new PluginLibrary();

        $basePath = MYBB_ROOT.'inc/plugins/PM_Edit/Templates/';

        $pm_edit_files = array(
            'edit_button' => 'pm_edit_button.html',
            'edit_form' => 'pm_edit_form.html',
            'edit_history' => 'pm_edit_history.html',
            'edit_history_item' => 'pm_edit_history_item.html',
            'edit_history_empty' => 'pm_edit_history_empty.html',
            'edit_history_link' => 'pm_edit_history_link.html',
            'edit_notice' => 'pm_edit_notice.html',
            'edit_reason_field' => 'pm_edit_reason_field.html'
        );

        $pm_edit_list = array();
        foreach($pm_edit_files as $key => $file)
        {
            $path = $basePath.$file;
            if(file_exists($path))
            {
                $pm_edit_list[$key] = file_get_contents($path);
            }
        }

        if($pm_edit_list)
        {
            $PL->templates('pmedit', 'PM Edit', $pm_edit_list);
        }
    }
    else
    {
        // Fallback: manually add templates if PluginLibrary is not available
        // First, create this plugin's templategroup if it does not already exist
        $query = $db->simple_select('templategroups', 'gid', "prefix='pm_edit'");
        if(!$db->fetch_field($query, 'gid'))
        {
            // Insert Template group
            $templategroup = array(
                "prefix" => "pm_edit",
                "title" => "PM Edit",
            );
            $db->insert_query("templategroups", $templategroup);
        }

        $templates_dir = MYBB_ROOT.'inc/plugins/PM_Edit/Templates/';
        if(!is_dir($templates_dir)) {
            return;
        }

        $templates = array();
        $dir = opendir($templates_dir);
        while(($file = readdir($dir)) !== false)
        {
            if(preg_match('#\.html$#i', $file))
            {
                $template_name = str_replace('.html', '', $file);
                $template_content = file_get_contents($templates_dir . $file);
                $templates[$template_name] = $template_content;
            }
        }
        closedir($dir);

        // Insert templates into database
        foreach($templates as $name => $content)
        {
            // Check if template already exists
            $query = $db->simple_select("templates", "tid", "title='".$db->escape_string($name)."' AND sid='-2'");
            if($db->num_rows($query) > 0)
            {
                // Update existing template
                $db->update_query("templates", array('template' => $db->escape_string($content)), "title='".$db->escape_string($name)."' AND sid='-2'");
            }
            else
            {
                // Insert new template
                $db->insert_query("templates", array(
                    'title' => $db->escape_string($name),
                    'template' => $db->escape_string($content),
                    'sid' => -2,
                    'version' => '1827',
                    'status' => '',
                    'dateline' => TIME_NOW
                ));
            }
        }
    }
}

function pm_edit_remove_templates()
{
    global $db;

    $templates_dir = MYBB_ROOT.'inc/plugins/PM_Edit/Templates/';
    if(!is_dir($templates_dir)) {
        return;
    }

    $dir = opendir($templates_dir);
    while(($file = readdir($dir)) !== false)
    {
        if(preg_match('#\.html$#i', $file))
        {
            $template_name = str_replace('.html', '', $file);
            $db->delete_query("templates", "title='".$db->escape_string($template_name)."' AND sid='-2'");
        }
    }
    closedir($dir);
}
