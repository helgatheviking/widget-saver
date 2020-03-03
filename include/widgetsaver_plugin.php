<?php
/**
 * Main plugin class.
 */
class PNDL_WidgetSaverPlugin {
    const SAVE_LAYOUT = "saveLayout";
    const RESTORE_LAYOUT = "restoreLayout";
    const DELETE_LAYOUT = "deleteLayout";
    const ADD_LAYOUT = "addLayout";

    const ADD_LAYOUT_KEY = "add_layout_key";
    const LAYOUT_KEY = "layout_key";

    const WIDGET_SAVER_FORM_SUBMISSION = "widget_saver_form_submission";

    const OPTIONS_KEY = "pndl_widget_saver_options";
    const LEGACY_OPTIONS_KEY = "pndl_stored_widgets";

	private $pluginFile;
	private $wpdb;
	private $updateMessage = NULL;
    private $options;

    /** @noinspection PhpUnusedPrivateMethodInspection constructor used from static factory method */
    private function __construct($pluginFile) {
		global $wpdb;

		$this->pluginFile = $pluginFile;
		$this->wpdb = $wpdb;

        $this->options = get_option(self::OPTIONS_KEY, array('layouts'=>array()));

        // merge legacy options into new option format
        $legacy_options = get_option(self::LEGACY_OPTIONS_KEY);
        if ($legacy_options) {
            $c = 0;
            foreach ($legacy_options as $theme=>$layout) {
                $c++;
                $this->options['layouts']['Legacy '.$c] = array('theme' => $theme, 'layout' => $layout);
            }
            delete_option(self::LEGACY_OPTIONS_KEY);
        }
	}

	/**
	 * Creates a new instance and registers the appropriate actions.
	 * @param string $pluginFile the path to the plugin file.
	 */
	public static function create($pluginFile) {
        // determine plugin path, taking into account havoc caused by symlinks - see http://wordpress.stackexchange.com/questions/15202/plugins-in-symlinked-directories#15204
        if (array_key_exists('mu_plugin', $GLOBALS)) {
            $pluginFile = $GLOBALS['mu_plugin'];
        }
        if (array_key_exists('network_plugin', $GLOBALS)) {
            $pluginFile = $GLOBALS['network_plugin'];
        }
        if (array_key_exists('plugin', $GLOBALS)) {
            $pluginFile = $GLOBALS['plugin'];
        }
		// instantiate the plugin
		$localPlugin = new PNDL_WidgetSaverPlugin($pluginFile);

		// register hook for displaying warning messages inline within the Plugins table.
		if (is_admin()) {
			// note that we're using "/" instead of DIRECTORY_SEPARATOR - it would appear that wordpress has the plugin
			// name registered using "/" regardless of platform.
			$pluginName = basename(dirname($pluginFile)) . "/" . basename($pluginFile);
			add_action("after_plugin_row_$pluginName", array($localPlugin, 'after_plugin_row'));

			// hook for admin_init: need to handle widget actions before widgets are processed by WP. parse_request
			// is not called for admin pages, so we do this in admin_init instead.
			add_action('admin_init', array($localPlugin, 'admin_init'));
			add_action('widgets_admin_page', array($localPlugin, 'widgets_admin_page'));
            add_action('admin_enqueue_scripts', array($localPlugin, 'admin_enqueue_scripts'));
		}
	}

	public function admin_init() {
        // return early if not a widget saver form submission
        if (!array_key_exists(self::WIDGET_SAVER_FORM_SUBMISSION, $_POST)) {
            return;
        }

		if (array_key_exists('resetWidgets', $_POST)) {
			update_option('sidebars_widgets', NULL);
			$this->updateMessage = 'Widgets have been reset.';
		} else if (array_key_exists(self::ADD_LAYOUT, $_POST)) {
            if (!array_key_exists(self::ADD_LAYOUT_KEY, $_POST) || strlen(trim($_POST[self::ADD_LAYOUT_KEY])) == 0) {
                $this->updateMessage = __('You must specify a name against which the current widget layout will be saved.', 'widget-saver');
            } else {
                $add_layout_key = trim($_POST[self::ADD_LAYOUT_KEY]);
                $this->save_layout($add_layout_key);
                $this->updateMessage = sprintf( __( 'Widget layout <em>%s</em> has been added.', 'widget-saver' ), $add_layout_key);
            }
        } else {
            $layout_key = array_key_exists(self::LAYOUT_KEY, $_POST) ? trim($_POST[self::LAYOUT_KEY]) : false;
            if (!$layout_key || strlen($layout_key) == 0) {
                $this->updateMessage = __( 'Invalid layout key selected', 'widget-saver' );
            } else {
                if (array_key_exists(self::SAVE_LAYOUT, $_POST)) {
                    $this->save_layout($layout_key);
                    $this->updateMessage = sprintf( __( 'Widget layout <em>%s</em> has been saved.', 'widget-saver' ), $layout_key);
                } else if (array_key_exists(self::RESTORE_LAYOUT, $_POST)) {
                    $widget_layout = $this->options['layouts'];
                    if ($widget_layout && array_key_exists($layout_key, $widget_layout)) {
                        $this->restore_layout($layout_key);
                        $this->updateMessage = sprintf( __( 'Widget layout <em>%s</em> has been restored.', 'widget-saver' ), $layout_key);
                        if ($this->options['layouts'][$layout_key]['theme'] != $this->get_current_theme_name()) {
                            $this->updateMessage .= sprintf( __( '<br /><strong>Note:</strong> This layout was originally saved for theme <em>%s</em>, which may not be compatible with your active theme (<em>%s</em>). Widgets may therefore appear in inactive sidebars.', 'widget-saver' ), $this->options['layouts'][$layout_key]['theme'], $this->get_current_theme_name() );
                        }
                    } else {
                        $this->updateMessage = sprintf( __( 'No widget layout has previously been saved for <em>%s</em>.', 'widget-saver' ), $layout_key);
                    }
                } else if (array_key_exists(self::DELETE_LAYOUT, $_POST)) {
                    $layout_key = $_POST[self::LAYOUT_KEY];
                    $this->delete_layout($layout_key);
                    $this->updateMessage = sprintf( __('Layout <em>%s</em> has been deleted.', 'widget-saver' ), $layout_key);
                }
            }
        }
	}

    public function admin_enqueue_scripts($hook) {
        if ('widgets.php' != $hook) {
            return;
        }
        $styles = plugins_url('css/widget-saver-styles.css', $this->pluginFile);
        wp_register_style('widget_saver_styles', $styles);
        wp_enqueue_style('widget_saver_styles');
    }

	public function widgets_admin_page() {
		if (!is_null($this->updateMessage)) {
			printf('<div class="updated below-h2"><p>%s</p></div>', $this->updateMessage);
		}

        //$selected_layout = array_key_exists('addLayoutKey', $_POST) ? $_POST['addLayoutKey'] : (array_key_exists('layoutKey', $_POST) ? $_POST['layoutKey'] : '');
        $selected_layout = array_key_exists('last_saved', $this->options) ? $this->options['last_saved'] : false;
        if ($selected_layout) {
            if ($this->options['layouts'][$selected_layout]['theme'] != $this->get_current_theme_name()) {
                $selected_layout = false;
            }
        }

		$saved_layouts_available = count($this->options['layouts']) > 0;
		echo   '<div class="wrap">';
		echo   '<h3>' . __('Widget Saver', 'widget-saver') . '</h3>';
		printf('<form method="post" action="%s">', $_SERVER["REQUEST_URI"]);
        printf('<input type="hidden" name="%s" value="true" />', self::WIDGET_SAVER_FORM_SUBMISSION);
        echo   '<table ><tbody>';
        echo   '<tr>';
        echo   '<td><label for="'.self::ADD_LAYOUT_KEY.'">' . __( 'Add Layout', 'widget-saver' ) . '</label></td>';
        printf('<td><input name="%1$s" id="%1$s" type="text" class="widget_saver_input" /></td>', self::ADD_LAYOUT_KEY);
        printf('<td><input class="button" name='.self::ADD_LAYOUT.' type="submit" value="%s" /></td>', __("Add", 'widget-saver'));
        echo   '</tr>';

        echo   '<tr>';
        echo   '<td><label for="'.self::LAYOUT_KEY.'">' . __( 'Layouts', 'widget-saver' ) . '</label></td>';
        printf('<td><select name="%1$s" id="%1$s" class="widget_saver_input"%2$s>%3$s</select></td>',
            self::LAYOUT_KEY, ($saved_layouts_available ? "" : " disabled"), $this->get_saved_keys_selector_options($selected_layout));
        echo   '<td>';
        printf('<input class="button" name='.self::SAVE_LAYOUT.' type="submit" value="%s"%s />', __("Save", 'widget-saver'),
            ($saved_layouts_available ? "" : " disabled"));
        printf('<input class="button" name='.self::RESTORE_LAYOUT.' type="submit" value="%s"%s />', __("Restore", 'widget-saver'),
            ($saved_layouts_available ? "" : " disabled"));
        printf('<input class="button" name='.self::DELETE_LAYOUT.' type="submit" value="%s"%s />', __("Delete", 'widget-saver'),
            ($saved_layouts_available ? "" : " disabled"));
        echo   '<td>';
        echo   '</tr>';
        echo   '<tr>';
        echo   '<td></td>';
        echo   '<td></td>';
        printf('<td><input class="button" name="resetWidgets" type="submit" value="%s" onclick="return confirm(\'%s\')"/></td>', esc_attr__("Clear All Widgets", 'widget-saver'), esc_html__( 'Are you sure you want to delete all widgets?', 'widget-saver' ) );
        echo   '</tr>';
		echo   '</tbody></table>';
        print('</form>');
		echo "</div>";
	}

	/**
	 * Callback implementation for adding messages after the plugin row in the plugins page.
	 */
	public function after_plugin_row() {
		// no messages to add at this stage.
	}

    /**
     * Returns the current theme name in a backwards-compatible fashion that avoids deprecation warnings.
     */
    private function get_current_theme_name() {
        // wp_get_theme was introduced in WordPress 3.4, leading to get_current_theme being deprecated
        if (function_exists('wp_get_theme')) {
            return wp_get_theme()->get('Name');
        } else {
            /** @noinspection PhpDeprecationInspection - required for backwards compatibility */
            return get_current_theme();
        }
    }

    /**
     * Builds the list of <code>option</code> declarations for each saved widget layout, returned as a string.
     * @param $selected_value string the widget layout to be shown as currently selected.
     * @return string the <code>option</code> declarations for each saved widget layout.
     */
    private function get_saved_keys_selector_options($selected_value) {
        $widget_layouts = $this->options['layouts'];
        $keys = array_keys($widget_layouts);

        if (!$keys) return '';

        foreach($widget_layouts as $layout_key => $layout) {
            $layouts[] = array('layout_key'=>$layout_key, 'theme'=>$layout['theme']);
        }
        usort($layouts, array('PNDL_WidgetSaverPlugin', 'sort_widget_layouts'));

        $options = '<option value="">-- ' . __( 'Please Select', 'widget-saver' ) . ' --</option>';

        foreach ($layouts as $layout) {
            $options = $options . '<option value="'.$layout['layout_key'].'" '.selected($selected_value, $layout['layout_key'], false).'>'
                .$layout['layout_key']." ({$layout['theme']})".'</option>';
        }

        return $options;
    }

    /**
     * Sorts layouts by theme first, then layout name.
     */
    /** @noinspection PhpUnusedPrivateMethodInspection invoked by callback */
    private static function sort_widget_layouts($layout1, $layout2) {
        $theme1 = $layout1['theme'];
        $theme2 = $layout2['theme'];
        $cmp = strcasecmp($theme1, $theme2);

        if ($cmp != 0) return $cmp;

        return strcmp($layout1['layout_key'], $layout2['layout_key']);
    }

    /**
     * Saves the current widget layout.
     * @param $layout_key string layout key against which the current layout will be saved.
     */
    private function save_layout($layout_key) {
        $this->options['layouts'][$layout_key] = array('theme' => $this->get_current_theme_name(), 'layout' => get_option('sidebars_widgets'));
        $this->options['last_saved'] = $layout_key;
        update_option(self::OPTIONS_KEY, $this->options);
    }

    /**
     * Deletes the specified layout.
     * @param $layout_key string the layout to be deleted.
     */
    private function delete_layout($layout_key) {
        unset($this->options['layouts'][$layout_key]);
        unset($this->options['last_saved']);
        update_option(self::OPTIONS_KEY, $this->options);
    }

    /**
     * Restores the layout previously saved.
     * @param $layout_key string the layout to be restored.
     */
    private function restore_layout($layout_key) {
        update_option('sidebars_widgets', $this->options['layouts'][$layout_key]['layout']);
        $this->options['last_saved'] = $layout_key;
        update_option(self::OPTIONS_KEY, $this->options);
    }
}
?>