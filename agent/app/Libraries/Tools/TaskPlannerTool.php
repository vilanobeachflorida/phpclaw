<?php

namespace App\Libraries\Tools;

/**
 * Persistent multi-step task planner for long coding sessions.
 *
 * Lets the agent break complex goals into ordered steps, track progress,
 * add notes/blockers, and checkpoint state so work survives context resets.
 *
 * Actions:
 *   create      – create a new plan from a goal
 *   list        – list all plans
 *   get         – get plan details
 *   update_step – update a step's status/notes
 *   add_step    – add a new step to an existing plan
 *   remove_step – remove a step
 *   checkpoint  – snapshot progress (which files changed, what's left)
 *   resume      – reload the latest checkpoint
 *   complete    – mark entire plan as done
 *   delete      – remove a plan
 */
class TaskPlannerTool extends BaseTool
{
    protected string $name = 'task_planner';
    protected string $description = 'Create and track multi-step plans for complex coding tasks with persistent checkpoints';

    protected function getDefaultConfig(): array
    {
        return [
            'enabled'   => true,
            'timeout'   => 10,
            'plans_dir' => 'writable/agent/plans',
            'max_plans' => 50,
        ];
    }

    public function getInputSchema(): array
    {
        return [
            'action'       => ['type' => 'string', 'required' => true, 'enum' => ['create', 'list', 'get', 'update_step', 'add_step', 'remove_step', 'checkpoint', 'resume', 'complete', 'delete']],
            'plan_id'      => ['type' => 'string', 'required' => false, 'description' => 'Plan identifier'],
            'goal'         => ['type' => 'string', 'required' => false, 'description' => 'High-level goal (action=create)'],
            'steps'        => ['type' => 'array',  'required' => false, 'description' => 'Array of step descriptions (action=create)'],
            'step_index'   => ['type' => 'int',    'required' => false, 'description' => 'Step index (0-based)'],
            'status'       => ['type' => 'string', 'required' => false, 'enum' => ['pending', 'in_progress', 'done', 'failed', 'blocked', 'skipped']],
            'notes'        => ['type' => 'string', 'required' => false, 'description' => 'Notes or context for a step/checkpoint'],
            'description'  => ['type' => 'string', 'required' => false, 'description' => 'Step description (action=add_step)'],
            'files_changed'=> ['type' => 'array',  'required' => false, 'description' => 'Files modified (action=checkpoint)'],
        ];
    }

    public function execute(array $args): array
    {
        if ($err = $this->requireArgs($args, ['action'])) return $err;

        $dir = $this->plansDir();

        switch ($args['action']) {
            case 'create':
                return $this->createPlan($dir, $args);
            case 'list':
                return $this->listPlans($dir);
            case 'get':
                if ($err = $this->requireArgs($args, ['plan_id'])) return $err;
                return $this->getPlan($dir, $args['plan_id']);
            case 'update_step':
                if ($err = $this->requireArgs($args, ['plan_id', 'step_index'])) return $err;
                return $this->updateStep($dir, $args);
            case 'add_step':
                if ($err = $this->requireArgs($args, ['plan_id', 'description'])) return $err;
                return $this->addStep($dir, $args);
            case 'remove_step':
                if ($err = $this->requireArgs($args, ['plan_id', 'step_index'])) return $err;
                return $this->removeStep($dir, $args);
            case 'checkpoint':
                if ($err = $this->requireArgs($args, ['plan_id'])) return $err;
                return $this->checkpoint($dir, $args);
            case 'resume':
                if ($err = $this->requireArgs($args, ['plan_id'])) return $err;
                return $this->resume($dir, $args['plan_id']);
            case 'complete':
                if ($err = $this->requireArgs($args, ['plan_id'])) return $err;
                return $this->completePlan($dir, $args['plan_id']);
            case 'delete':
                if ($err = $this->requireArgs($args, ['plan_id'])) return $err;
                return $this->deletePlan($dir, $args['plan_id']);
            default:
                return $this->error("Unknown action: {$args['action']}");
        }
    }

    // ── create ────────────────────────────────────────────────

    private function createPlan(string $dir, array $args): array
    {
        if ($err = $this->requireArgs($args, ['goal'])) return $err;

        $id = date('Ymd_His') . '_' . substr(md5(uniqid('', true)), 0, 6);
        $steps = [];

        foreach (($args['steps'] ?? []) as $i => $desc) {
            $steps[] = [
                'index'       => $i,
                'description' => is_string($desc) ? $desc : ($desc['description'] ?? ''),
                'status'      => 'pending',
                'notes'       => '',
                'updated_at'  => null,
            ];
        }

        $plan = [
            'id'          => $id,
            'goal'        => $args['goal'],
            'status'      => 'active',
            'steps'       => $steps,
            'checkpoints' => [],
            'created_at'  => date('c'),
            'updated_at'  => date('c'),
        ];

        $this->savePlan($dir, $id, $plan);

        return $this->success($plan, "Plan created: {$id}");
    }

    // ── list ──────────────────────────────────────────────────

    private function listPlans(string $dir): array
    {
        if (!is_dir($dir)) return $this->success(['plans' => [], 'count' => 0]);

        $plans = [];
        foreach (glob("{$dir}/*.json") as $file) {
            $data = json_decode(file_get_contents($file), true);
            if (!$data) continue;
            $total = count($data['steps'] ?? []);
            $done  = count(array_filter($data['steps'] ?? [], fn($s) => $s['status'] === 'done'));
            $plans[] = [
                'id'         => $data['id'],
                'goal'       => $data['goal'],
                'status'     => $data['status'],
                'steps'      => $total,
                'completed'  => $done,
                'progress'   => $total > 0 ? round(($done / $total) * 100) . '%' : '0%',
                'created_at' => $data['created_at'],
                'updated_at' => $data['updated_at'],
            ];
        }

        usort($plans, fn($a, $b) => strcmp($b['updated_at'], $a['updated_at']));

        return $this->success(['plans' => $plans, 'count' => count($plans)]);
    }

    // ── get ───────────────────────────────────────────────────

    private function getPlan(string $dir, string $id): array
    {
        $plan = $this->loadPlan($dir, $id);
        if (!$plan) return $this->error("Plan not found: {$id}");
        return $this->success($plan);
    }

    // ── update step ───────────────────────────────────────────

    private function updateStep(string $dir, array $args): array
    {
        $plan = $this->loadPlan($dir, $args['plan_id']);
        if (!$plan) return $this->error("Plan not found: {$args['plan_id']}");

        $idx = (int)$args['step_index'];
        if (!isset($plan['steps'][$idx])) return $this->error("Step index out of range: {$idx}");

        if (isset($args['status'])) $plan['steps'][$idx]['status'] = $args['status'];
        if (isset($args['notes']))  $plan['steps'][$idx]['notes'] = $args['notes'];
        $plan['steps'][$idx]['updated_at'] = date('c');
        $plan['updated_at'] = date('c');

        $this->savePlan($dir, $args['plan_id'], $plan);

        return $this->success($plan['steps'][$idx], "Step {$idx} updated");
    }

    // ── add step ──────────────────────────────────────────────

    private function addStep(string $dir, array $args): array
    {
        $plan = $this->loadPlan($dir, $args['plan_id']);
        if (!$plan) return $this->error("Plan not found: {$args['plan_id']}");

        $newIdx = count($plan['steps']);
        $step = [
            'index'       => $newIdx,
            'description' => $args['description'],
            'status'      => 'pending',
            'notes'       => $args['notes'] ?? '',
            'updated_at'  => null,
        ];

        $plan['steps'][] = $step;
        $plan['updated_at'] = date('c');

        $this->savePlan($dir, $args['plan_id'], $plan);

        return $this->success($step, "Step {$newIdx} added");
    }

    // ── remove step ───────────────────────────────────────────

    private function removeStep(string $dir, array $args): array
    {
        $plan = $this->loadPlan($dir, $args['plan_id']);
        if (!$plan) return $this->error("Plan not found: {$args['plan_id']}");

        $idx = (int)$args['step_index'];
        if (!isset($plan['steps'][$idx])) return $this->error("Step index out of range: {$idx}");

        $removed = $plan['steps'][$idx];
        array_splice($plan['steps'], $idx, 1);

        // Re-index
        foreach ($plan['steps'] as $i => &$s) {
            $s['index'] = $i;
        }

        $plan['updated_at'] = date('c');
        $this->savePlan($dir, $args['plan_id'], $plan);

        return $this->success($removed, "Step {$idx} removed");
    }

    // ── checkpoint ────────────────────────────────────────────

    private function checkpoint(string $dir, array $args): array
    {
        $plan = $this->loadPlan($dir, $args['plan_id']);
        if (!$plan) return $this->error("Plan not found: {$args['plan_id']}");

        $checkpoint = [
            'timestamp'     => date('c'),
            'notes'         => $args['notes'] ?? '',
            'files_changed' => $args['files_changed'] ?? [],
            'step_summary'  => [],
        ];

        foreach ($plan['steps'] as $s) {
            $checkpoint['step_summary'][] = [
                'index'  => $s['index'],
                'status' => $s['status'],
                'desc'   => mb_substr($s['description'], 0, 80),
            ];
        }

        $plan['checkpoints'][] = $checkpoint;
        $plan['updated_at'] = date('c');

        $this->savePlan($dir, $args['plan_id'], $plan);

        return $this->success($checkpoint, 'Checkpoint saved');
    }

    // ── resume ────────────────────────────────────────────────

    private function resume(string $dir, string $id): array
    {
        $plan = $this->loadPlan($dir, $id);
        if (!$plan) return $this->error("Plan not found: {$id}");

        $completed = [];
        $pending   = [];
        $current   = null;

        foreach ($plan['steps'] as $s) {
            if ($s['status'] === 'done' || $s['status'] === 'skipped') {
                $completed[] = $s;
            } elseif ($s['status'] === 'in_progress') {
                $current = $s;
            } else {
                $pending[] = $s;
            }
        }

        $lastCheckpoint = !empty($plan['checkpoints']) ? end($plan['checkpoints']) : null;

        return $this->success([
            'plan_id'         => $id,
            'goal'            => $plan['goal'],
            'completed_steps' => $completed,
            'current_step'    => $current,
            'pending_steps'   => $pending,
            'last_checkpoint' => $lastCheckpoint,
            'progress'        => count($plan['steps']) > 0
                ? round((count($completed) / count($plan['steps'])) * 100) . '%'
                : '0%',
        ]);
    }

    // ── complete / delete ─────────────────────────────────────

    private function completePlan(string $dir, string $id): array
    {
        $plan = $this->loadPlan($dir, $id);
        if (!$plan) return $this->error("Plan not found: {$id}");

        $plan['status'] = 'completed';
        $plan['completed_at'] = date('c');
        $plan['updated_at'] = date('c');
        $this->savePlan($dir, $id, $plan);

        return $this->success(['id' => $id, 'status' => 'completed']);
    }

    private function deletePlan(string $dir, string $id): array
    {
        $path = "{$dir}/{$id}.json";
        if (!file_exists($path)) return $this->error("Plan not found: {$id}");
        unlink($path);
        return $this->success(['deleted' => $id]);
    }

    // ── persistence helpers ───────────────────────────────────

    private function plansDir(): string
    {
        $dir = WRITEPATH . 'agent/plans';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        return $dir;
    }

    private function loadPlan(string $dir, string $id): ?array
    {
        $path = "{$dir}/{$id}.json";
        if (!file_exists($path)) return null;
        return json_decode(file_get_contents($path), true);
    }

    private function savePlan(string $dir, string $id, array $plan): void
    {
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        file_put_contents("{$dir}/{$id}.json", json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
