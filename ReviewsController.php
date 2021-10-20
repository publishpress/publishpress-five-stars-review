<?php
/**
 * @package PublishPress
 * @author  PublishPress
 *
 * Copyright (c) 2021 PublishPress
 *
 * FiveStarsReview is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * FiveStarsReview is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with PublishPress.  If not, see <http://www.gnu.org/licenses/>.
 *
 * ---------------------------------------------------------------------
 * It includes:
 * - Multiple trigger groups which can be ordered by priority.
 * - Multiple triggers per group.
 * - Customizable messaging per trigger.
 * - Link to review page.
 * - Request reviews on a per-user basis rather than per site.
 * - Allows each user to dismiss it until later or permanently seamlessly via AJAX.
 * - Integrates with attached tracking server to keep anonymous records of each trigger's effectiveness.
 *   - Tracking Server API: https://gist.github.com/danieliser/0d997532e023c46d38e1bdfd50f38801
 *
 * Original Author: danieliser
 * Original Author URL: https://danieliser.com
 * URL: https://github.com/danieliser/WP-Product-In-Dash-Review-Requests
 */

namespace PublishPress\FiveStarsReview;


use Exception;

/**
 * Class ReviewsController
 *
 * @package PublishPress\FiveStarsReview
 */
class ReviewsController
{
    /**
     * @var string
     */
    private $pluginSlug;

    /**
     * @var string
     */
    private $pluginName;

    /**
     * @var string
     */
    private $apiUrl = '';

    /**
     * @var array
     */
    private $metaMap;

    /**
     * @param string $pluginSlug
     * @param string $pluginName
     */
    public function __construct($pluginSlug, $pluginName)
    {
        $this->pluginSlug = $pluginSlug;
        $this->pluginName = $pluginName;

        $this->metaMap = apply_filters(
            'publishpress_reviews_meta_map_' . $pluginSlug,
            [
                'action_ajax_handler' => $this->pluginSlug . '_action',
                'option_installed_on' => $this->pluginSlug . '_reviews_installed_on',
                'nonce_action' => $this->pluginSlug . '_reviews_action',
                'user_meta_dismissed_triggers' => '_' . $this->pluginSlug . '_reviews_dismissed_triggers',
                'user_meta_last_dismissed' => '_' . $this->pluginSlug . '_reviews_last_dismissed',
                'user_meta_already_did' => '_' . $this->pluginSlug . '_reviews_already_did',
                'filter_triggers' => $this->pluginSlug . '_reviews_triggers',
            ]
        );
    }

    /**
     * Initialize the library.
     */
    public function init()
    {
        $this->addHooks();
    }

    /**
     * Hook into relevant WP actions.
     */
    private function addHooks()
    {
        if (is_admin() && current_user_can('edit_posts')) {
            $this->installationPath();
            add_action('wp_ajax_' . $this->metaMap['action_ajax_handler'], [$this, 'ajaxHandler']);
            add_action('admin_notices', [$this, 'renderAdminNotices']);
            add_action('network_admin_notices', [$this, 'renderAdminNotices']);
            add_action('user_admin_notices', [$this, 'renderAdminNotices']);
        }
    }

    /**
     * Get the installation date for comparisons. Sets the date to now if none is found.
     *
     * @return false|string
     */
    public function installationPath()
    {
        $installationPath = get_option($this->metaMap['option_installed_on'], false);

        if (! $installationPath) {
            $installationPath = current_time('mysql');
            update_option($this->metaMap['option_installed_on'], $installationPath);
        }

        return $installationPath;
    }

    /**
     * The function called by the ajax request.
     */
    public function ajaxHandler()
    {
        $args = wp_parse_args(
            $_REQUEST,
            [
                'group' => $this->getTriggerGroup(),
                'code' => $this->getTriggerCode(),
                'priority' => $this->getCurrentTrigger('priority'),
                'reason' => 'maybe_later',
            ]
        );

        if (! wp_verify_nonce($_REQUEST['nonce'], $this->metaMap['nonce_action'])) {
            wp_send_json_error();
        }

        try {
            $userId = get_current_user_id();

            $dismissedTriggers = $this->getDismissedTriggerGroups();
            $dismissedTriggers[$args['group']] = (int)$args['priority'];

            update_user_meta($userId, $this->metaMap['user_meta_dismissed_triggers'], $dismissedTriggers);
            update_user_meta($userId, $this->metaMap['user_meta_last_dismissed'], current_time('mysql'));

            switch ($args['reason']) {
                case 'maybe_later':
                    update_user_meta($userId, $this->metaMap['user_meta_last_dismissed'], current_time('mysql'));
                    break;
                case 'am_now':
                case 'already_did':
                    $this->setUserAlreadyDid($userId);
                    break;
            }

            wp_send_json_success();
        } catch (Exception $e) {
            wp_send_json_error($e);
        }
    }

    /**
     * Get the trigger group.
     *
     * @return int|string
     */
    private function getTriggerGroup()
    {
        static $selected;

        if (! isset($selected)) {
            $dismissedTriggers = $this->getDismissedTriggerGroups();

            $triggers = $this->getTriggers();

            foreach ($triggers as $g => $group) {
                foreach ($group['triggers'] as $trigger) {
                    if (! in_array(
                            false,
                            $trigger['conditions']
                        ) && (empty($dismissedTriggers[$g]) || $dismissedTriggers[$g] < $trigger['priority'])) {
                        $selected = $g;
                        break;
                    }
                }

                if (isset($selected)) {
                    break;
                }
            }
        }

        return $selected;
    }

    /**
     * Returns an array of dismissed trigger groups.
     *
     * Array contains the group key and highest priority trigger that has been shown previously for each group.
     *
     * $return = array(
     *   'group1' => 20
     * );
     *
     * @return array|mixed
     */
    private function getDismissedTriggerGroups()
    {
        $userId = get_current_user_id();

        $dismissedTriggers = get_user_meta($userId, $this->metaMap['user_meta_dismissed_triggers'], true);

        if (! $dismissedTriggers) {
            $dismissedTriggers = [];
        }

        return $dismissedTriggers;
    }

    /**
     * Gets a list of triggers.
     *
     * @param null $group
     * @param null $code
     *
     * @return bool|mixed|void
     */
    private function getTriggers($group = null, $code = null)
    {
        static $triggers;

        if (! isset($triggers)) {
            $timeMessage = __(
                'Hey, you\'ve been using %s for %s on your site - I hope it has been helpful. I would very much appreciate it if you could quickly give it a 5-star rating on WordPress, just to help us spread the word.',
                $this->pluginSlug
            );

            $triggers = apply_filters(
                $this->metaMap['filter_triggers'],
                [
                    'time_installed' => [
                        'triggers' => [
                            'one_week' => [
                                'message' => sprintf($timeMessage, $this->pluginName, __('1 week', $this->pluginSlug)),
                                'conditions' => [
                                    strtotime($this->installationPath() . ' +1 week') < time(),
                                ],
                                'link' => 'https://wordpress.org/support/plugin/' . $this->pluginSlug . '/reviews/?rate=5#rate-response',
                                'priority' => 10,
                            ],
                            'one_month' => [
                                'message' => sprintf($timeMessage, $this->pluginName, __('1 month', $this->pluginSlug)),
                                'conditions' => [
                                    strtotime($this->installationPath() . ' +1 month') < time(),
                                ],
                                'link' => 'https://wordpress.org/support/plugin/' . $this->pluginSlug . '/reviews/?rate=5#rate-response',
                                'priority' => 20,
                            ],
                            'three_months' => [
                                'message' => sprintf(
                                    $timeMessage,
                                    $this->pluginName,
                                    __('3 months', $this->pluginSlug)
                                ),
                                'conditions' => [
                                    strtotime($this->installationPath() . ' +3 months') < time(),
                                ],
                                'link' => 'https://wordpress.org/support/plugin/' . $this->pluginSlug . '/reviews/?rate=5#rate-response',
                                'priority' => 30,
                            ],
                        ],
                        'priority' => 10,
                    ],
                ]
            );

            // Sort Groups
            uasort($triggers, [$this, 'rsortByPriority']);

            // Sort each groups triggers.
            foreach ($triggers as $v) {
                uasort($v['triggers'], [$this, 'rsortByPriority']);
            }
        }

        if (isset($group)) {
            if (! isset($triggers[$group])) {
                return false;
            }

            if (! isset($code)) {
                $return = $triggers[$group];
            } elseif (isset($triggers[$group]['triggers'][$code])) {
                $return = $triggers[$group]['triggers'][$code];
            } else {
                $return = false;
            }

            return $return;
        }

        return $triggers;
    }

    /**
     * @return int|string
     */
    private function getTriggerCode()
    {
        static $selected;

        if (! isset($selected)) {
            $dismissedTriggers = $this->getDismissedTriggerGroups();

            foreach ($this->getTriggers() as $g => $group) {
                foreach ($group['triggers'] as $t => $trigger) {
                    if (! in_array(
                            false,
                            $trigger['conditions']
                        ) && (empty($dismissedTriggers[$g]) || $dismissedTriggers[$g] < $trigger['priority'])) {
                        $selected = $t;
                        break;
                    }
                }

                if (isset($selected)) {
                    break;
                }
            }
        }

        return $selected;
    }

    /**
     * @param null $key
     *
     * @return bool|mixed|void
     */
    private function getCurrentTrigger($key = null)
    {
        $group = $this->getTriggerGroup();
        $code = $this->getTriggerCode();

        if (! $group || ! $code) {
            return false;
        }

        $trigger = $this->getTriggers($group, $code);

        if (empty($key)) {
            $return = $trigger;
        } elseif (isset($trigger[$key])) {
            $return = $trigger[$key];
        } else {
            $return = false;
        }

        return $return;
    }

    /**
     * @param $userId
     * @param bool $value
     */
    private function setUserAlreadyDid($userId)
    {
        update_user_meta($userId, $this->metaMap['user_meta_already_did'], true);
    }

    /**
     * Render admin notices if available.
     */
    public function renderAdminNotices()
    {
        if ($this->hideNotices()) {
            return;
        }

        $group = $this->getTriggerGroup();
        $code = $this->getTriggerCode();
        $priority = $this->getCurrentTrigger('priority');
        $trigger = $this->getCurrentTrigger();

        // Used to anonymously distinguish unique site+user combinations in terms of effectiveness of each trigger.
        $uuid = wp_hash(home_url() . '-' . get_current_user_id());

        ?>

        <script type="text/javascript">
            (function ($) {
                var trigger = {
                    group: '<?php echo $group; ?>',
                    code: '<?php echo $code; ?>',
                    priority: '<?php echo $priority; ?>'
                };

                function dismiss(reason) {
                    $.ajax({
                        method: "POST",
                        dataType: "json",
                        url: ajaxurl,
                        data: {
                            action: '<?php echo $this->metaMap['action_ajax_handler']; ?>',
                            nonce: '<?php echo wp_create_nonce($this->metaMap['nonce_action']); ?>',
                            group: trigger.group,
                            code: trigger.code,
                            priority: trigger.priority,
                            reason: reason
                        }
                    });

                    <?php if ( ! empty($this->apiUrl) ) : ?>
                    $.ajax({
                        method: "POST",
                        dataType: "json",
                        url: '<?php echo $this->apiUrl; ?>',
                        data: {
                            trigger_group: trigger.group,
                            trigger_code: trigger.code,
                            reason: reason,
                            uuid: '<?php echo $uuid; ?>'
                        }
                    });
                    <?php endif; ?>
                }

                $(document)
                    .on('click', '.<?php echo $this->pluginSlug; ?>-notice .<?php echo $this->pluginSlug; ?>-dismiss', function (event) {
                        var $this = $(this),
                            reason = $this.data('reason'),
                            notice = $this.parents('.<?php echo $this->pluginSlug; ?>-notice');

                        notice.fadeTo(100, 0, function () {
                            notice.slideUp(100, function () {
                                notice.remove();
                            });
                        });

                        dismiss(reason);
                    })
                    .ready(function () {
                        setTimeout(function () {
                            $('.<?php echo $this->pluginSlug; ?>-notice button.notice-dismiss').click(function (event) {
                                dismiss('maybe_later');
                            });
                        }, 1000);
                    });
            }(jQuery));
        </script>

        <div class="notice notice-success is-dismissible <?php
        echo $this->pluginSlug; ?>-notice">

            <p>
                <?php
                echo $trigger['message']; ?>
            </p>
            <p>
                <a class="button button-primary <?php
                echo $this->pluginSlug; ?>-dismiss" target="_blank"
                   href="<?php
                   echo $trigger['link']; ?>"
                   data-reason="am_now">
                    <strong><?php
                        _e('Ok, you deserve it', $this->pluginSlug); ?></strong>
                </a> <a href="#" class="button <?php
                echo $this->pluginSlug; ?>-dismiss" data-reason="maybe_later">
                    <?php
                    _e('Nope, maybe later', $this->pluginSlug); ?>
                </a> <a href="#" class="button <?php
                echo $this->pluginSlug; ?>-dismiss" data-reason="already_did">
                    <?php
                    _e('I already did', $this->pluginSlug); ?>
                </a>
            </p>

        </div>

        <?php
    }

    /**
     * Checks if notices should be shown.
     *
     * @return bool
     */
    private function hideNotices()
    {
        $conditions = [
            $this->userSelectedAlreadyDid(),
            $this->lastDismissedDate() && strtotime($this->lastDismissedDate() . ' +2 weeks') > time(),
            empty($this->getTriggerCode()),
        ];

        return in_array(true, $conditions);
    }

    /**
     * Returns true if the user has opted to never see this again.
     *
     * @return bool
     */
    private function userSelectedAlreadyDid()
    {
        $userId = get_current_user_id();

        return (bool)get_user_meta($userId, $this->metaMap['user_meta_already_did'], true);
    }

    /**
     * Gets the last dismissed date.
     *
     * @return false|string
     */
    private function lastDismissedDate()
    {
        $userId = get_current_user_id();

        return get_user_meta($userId, $this->metaMap['user_meta_last_dismissed'], true);
    }

    /**
     * Sort array by priority value
     *
     * @param $a
     * @param $b
     *
     * @return int
     */
    public function sortByPriority($a, $b)
    {
        if (! isset($a['priority']) || ! isset($b['priority']) || $a['priority'] === $b['priority']) {
            return 0;
        }

        return ($a['priority'] < $b['priority']) ? -1 : 1;
    }

    /**
     * Sort array in reverse by priority value
     *
     * @param $a
     * @param $b
     *
     * @return int
     */
    public function rsortByPriority($a, $b)
    {
        if (! isset($a['priority']) || ! isset($b['priority']) || $a['priority'] === $b['priority']) {
            return 0;
        }

        return ($a['priority'] < $b['priority']) ? 1 : -1;
    }
}