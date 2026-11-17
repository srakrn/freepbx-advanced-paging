<?php

namespace AdvancedPaging;

use InvalidArgumentException;
use PDOException;
use Throwable;

/**
 * Server-rendered FreePBX administration page.
 * The caller must enforce FreePBX section authorization before invoking it.
 */
final class AdminPage
{
    private $module;
    private $core;
    private $recordings;

    public function __construct($module, $core, $recordings)
    {
        $this->module = $module;
        $this->core = $core;
        $this->recordings = $recordings;
    }

    public function handle(string $method, array $query, array $post, array &$session): array
    {
        if (!isset($session['advancedpaging_csrf']) || !is_string($session['advancedpaging_csrf'])) {
            $session['advancedpaging_csrf'] = bin2hex(random_bytes(32));
        }
        $token = $session['advancedpaging_csrf'];
        $error = '';
        $notice = '';
        $view = $query['view'] ?? '';
        $id = null;
        if (isset($query['id'])) {
            $id = $this->parseId($query['id']);
            if ($id === null) {
                return ['status' => 400, 'html' => $this->alert('Invalid group identifier.', 'danger')];
            }
        }
        if ($method === 'POST') {
            if (!isset($post['csrf']) || !is_string($post['csrf']) || !hash_equals($token, $post['csrf'])) {
                return ['status' => 403, 'html' => $this->alert('Your session token is invalid. Reload the page and try again.', 'danger')];
            }
            $action = $post['action'] ?? '';
            if (!is_string($action) || !in_array($action, ['save', 'delete'], true)) {
                return ['status' => 400, 'html' => $this->alert('Invalid request.', 'danger')];
            }
            $id = null;
            if (isset($post['id']) && $post['id'] !== '') {
                $id = $this->parseId($post['id']);
                if ($id === null) {
                    return ['status' => 400, 'html' => $this->alert('Invalid group identifier.', 'danger')];
                }
            }
            try {
                if ($action === 'delete') {
                    if ($id === null || !$this->module->deleteGroup($id)) {
                        throw new InvalidArgumentException('Group does not exist.');
                    }
                    $notice = 'Group deleted. Apply configuration to activate changes.';
                } else {
                    $input = [
                        'name' => $post['name'] ?? null,
                        'extension' => $post['extension'] ?? null,
                        'enabled' => $post['enabled'] ?? null,
                        'audio_mode' => $post['audio_mode'] ?? null,
                        'members' => $post['members'] ?? [],
                        'pre_recording_id' => $post['pre_recording_id'] ?? null,
                        'post_recording_id' => $post['post_recording_id'] ?? null,
                    ];
                    $id = $this->module->saveGroup($input, $id);
                    $notice = 'Group saved. Apply configuration to activate changes.';
                }
                $session['advancedpaging_csrf'] = bin2hex(random_bytes(32));
                $token = $session['advancedpaging_csrf'];
                $view = '';
                $id = null;
            } catch (InvalidArgumentException $exception) {
                $error = $exception->getMessage();
                $view = $action === 'save' ? 'edit' : '';
            } catch (PDOException $exception) {
                $error = 'The group could not be saved. Check for a duplicate extension and try again.';
                $view = $action === 'save' ? 'edit' : '';
            } catch (Throwable $exception) {
                $error = 'The request could not be completed. Check the FreePBX logs.';
                $view = $action === 'save' ? 'edit' : '';
            }
        } elseif ($method !== 'GET') {
            return ['status' => 405, 'html' => $this->alert('Unsupported request method.', 'danger')];
        }
        $html = '<div class="container-fluid"><h1>' . self::escape('Advanced Paging') . '</h1>';
        if ($notice !== '') {
            $html .= $this->alert($notice, 'success');
        }
        if ($error !== '') {
            $html .= $this->alert($error, 'danger');
        }
        if ($view === 'edit' || $view === 'add') {
            $group = $id !== null ? $this->module->getGroup($id) : null;
            if ($id !== null && $group === null) {
                return ['status' => 404, 'html' => $html . $this->alert('Group does not exist.', 'danger') . '</div>'];
            }
            $group = $group ?? [
                'name' => '', 'extension' => '', 'enabled' => '1', 'audio_mode' => 'simplex',
                'members' => [], 'pre_recording_id' => null, 'post_recording_id' => null,
            ];
            if ($method === 'POST' && $error !== '') {
                foreach (['name', 'extension', 'enabled', 'audio_mode', 'members', 'pre_recording_id', 'post_recording_id'] as $key) {
                    if (isset($post[$key]) && (is_string($post[$key]) || ($key === 'members' && is_array($post[$key])))) {
                        $group[$key] = $post[$key];
                    }
                }
            }
            $html .= $this->editor($group, $id, $token);
        } else {
            $html .= $this->listing($token);
        }
        return ['status' => 200, 'html' => $html . '</div>'];
    }

    private function listing(string $token): string
    {
        $html = '<p><a class="btn btn-primary" href="?display=advancedpaging&amp;view=add">Add Group</a></p>';
        $html .= '<div class="table-responsive"><table class="table table-striped"><thead><tr>'
            . '<th>Name</th><th>Extension</th><th>Audio mode</th><th>Members</th><th>Status</th><th>Actions</th>'
            . '</tr></thead><tbody>';
        foreach ($this->module->listGroups() as $group) {
            $id = (int) $group['id'];
            $html .= '<tr><td>' . self::escape($group['name']) . '</td><td>' . self::escape($group['extension'])
                . '</td><td>' . (($group['audio_mode'] ?? '') === 'duplex' ? 'Duplex' : 'One-way')
                . '</td><td>' . (int) $group['member_count'] . '</td><td>'
                . (!empty($group['enabled']) ? 'Enabled' : 'Disabled') . '</td><td>'
                . '<a class="btn btn-default btn-sm" href="?display=advancedpaging&amp;view=edit&amp;id=' . $id . '">Edit</a> '
                . '<form method="post" action="?display=advancedpaging" style="display:inline">'
                . $this->hidden('csrf', $token) . $this->hidden('action', 'delete') . $this->hidden('id', (string) $id)
                . '<button type="submit" class="btn btn-danger btn-sm" onclick="return confirm(\'Delete this group?\')">Delete</button></form>'
                . '</td></tr>';
        }
        return $html . '</tbody></table></div>';
    }

    private function editor(array $group, ?int $id, string $token): string
    {
        $html = '<form method="post" action="?display=advancedpaging" class="form-horizontal">'
            . $this->hidden('csrf', $token) . $this->hidden('action', 'save')
            . $this->hidden('id', $id === null ? '' : (string) $id)
            . '<h3>General</h3>';
        $html .= $this->field('Group name', '<input class="form-control" name="name" required maxlength="255" value="' . self::escape($group['name']) . '">');
        $html .= $this->field('Paging extension', '<input class="form-control" name="extension" required pattern="[0-9]{1,25}" value="' . self::escape($group['extension']) . '">');
        $html .= $this->field('Enabled', $this->select('enabled', ['1' => 'Enabled', '0' => 'Disabled'], (string) $group['enabled']));
        $users = $this->core->getAllUsers();
        $options = [];
        foreach ($users as $extension => $user) {
            $options[(string) $extension] = (string) $extension . ' — ' . (string) ($user['name'] ?? '');
        }
        $selected = is_array($group['members']) ? $group['members'] : [];
        $html .= '<h3>Members</h3>' . $this->field('Receiving extensions', $this->select('members[]', $options, $selected, true));
        $html .= '<h3>Audio</h3>' . $this->field('Audio mode', $this->select('audio_mode', ['simplex' => 'One-way', 'duplex' => 'Duplex'], (string) $group['audio_mode']));
        $recordings = ['' => 'None'];
        foreach ($this->recordings->getAllRecordings() as $recording) {
            if (isset($recording['id'], $recording['displayname'])) {
                $recordings[(string) $recording['id']] = (string) $recording['displayname'];
            }
        }
        $html .= $this->field('Pre-page Recording', $this->select('pre_recording_id', $recordings, (string) ($group['pre_recording_id'] ?? '')));
        $html .= $this->field('Post-page Recording', $this->select('post_recording_id', $recordings, (string) ($group['post_recording_id'] ?? '')));
        return $html . '<p><button type="submit" class="btn btn-primary">Submit</button> '
            . '<a class="btn btn-default" href="?display=advancedpaging">Cancel</a></p></form>';
    }

    private function select(string $name, array $options, $selected, bool $multiple = false): string
    {
        $selected = array_values(array_map('strval', array_filter(
            is_array($selected) ? $selected : [$selected],
            static function ($value): bool { return is_string($value) || is_int($value); }
        )));
        $html = '<select class="form-control" name="' . self::escape($name) . '"' . ($multiple ? ' multiple size="10"' : '') . '>';
        foreach ($options as $value => $label) {
            $value = (string) $value;
            $html .= '<option value="' . self::escape($value) . '"' . (in_array($value, $selected, true) ? ' selected' : '')
                . '>' . self::escape($label) . '</option>';
        }
        return $html . '</select>';
    }

    private function field(string $label, string $input): string
    {
        return '<div class="form-group"><label class="control-label col-sm-3">' . self::escape($label)
            . '</label><div class="col-sm-6">' . $input . '</div></div>';
    }

    private function hidden(string $name, string $value): string
    {
        return '<input type="hidden" name="' . self::escape($name) . '" value="' . self::escape($value) . '">';
    }

    private function alert(string $message, string $type): string
    {
        return '<div class="alert alert-' . $type . '">' . self::escape($message) . '</div>';
    }

    private function parseId($value): ?int
    {
        if ((!is_string($value) && !is_int($value)) || !preg_match('/^[1-9][0-9]{0,8}$/D', (string) $value)) {
            return null;
        }
        return (int) $value;
    }

    private static function escape($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
