<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Adapters\Panel;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use LaravelAgentAccess\Approvals\ApprovalService;
use LaravelAgentAccess\Exceptions\AccessException;
use LaravelAgentAccess\Grants\GrantService;
use LaravelAgentAccess\Identity\ContextFactory;
use LaravelAgentAccess\Requests\AccessRequestService;
use LaravelAgentAccess\Requests\QueryService;
use LaravelAgentAccess\Rules\RoleHierarchy;
use LaravelAgentAccess\Rules\RuleRegistry;

final readonly class PanelController
{
    public function __construct(private ContextFactory $contexts, private QueryService $queries, private AccessRequestService $requests,
        private ApprovalService $approvals, private GrantService $grants, private RoleHierarchy $hierarchy, private RuleRegistry $rules) {}

    public function __invoke(Request $request): View|RedirectResponse
    {
        $context = $this->contexts->authenticated($request->user(), 'http');
        $id = (string) $request->route('id', '');
        $operation = $request->route('operation');
        $canInspect = Gate::forUser($context->principal->user)->allows('agent_access.view_configuration');
        try {
            if ($operation !== null) {
                $input = $request->validate(['reason' => 'nullable|string|max:500', 'user_id' => 'prohibited', 'actor_id' => 'prohibited', 'principal' => 'prohibited']);
                match ($operation) {
                    'approve' => $this->approvals->approve($context, $id),
                    'reject' => $this->approvals->reject($context, $id, $input['reason'] ?? null),
                    'cancel' => $this->requests->cancel($context, $id),
                    'revoke' => $this->grants->revoke($context, $id),
                    default => throw new AccessException('OPERATION_NOT_FOUND', 404),
                };

                return redirect()->route($operation === 'revoke' ? 'agent-access.panel.grants' : 'agent-access.panel.request', $operation === 'revoke' ? [] : ['id' => $id])
                    ->with('agent_access_status', __('Access updated.'));
            }
            $screen = $request->route('screen');
            $input = $request->validate(['cursor' => 'nullable|string|max:4096', 'status' => 'nullable|in:pending,approved,rejected,cancelled,expired']);
            $data = ['principalId' => $context->principal->id, 'canInspect' => $canInspect];
            if ($screen === 'requests') {
                $data += ['records' => $this->queries->requestsCursor($context, cursor: $input['cursor'] ?? null, status: $input['status'] ?? 'pending'),
                    'status' => $input['status'] ?? 'pending'];
            } elseif ($screen === 'grants') {
                $data += ['records' => $this->queries->grantsCursor($context, cursor: $input['cursor'] ?? null)];
            } elseif ($screen === 'request') {
                $record = $this->requests->show($context, $id);
                $data += ['record' => $record, 'canApprove' => $record->status === 'pending' && $this->requests->canApprove($context, $record),
                    'canCancel' => $record->status === 'pending' && $this->requests->owns($context, $record)];
            } elseif ($screen === 'hierarchy') {
                if (! $canInspect) {
                    throw new AccessException('CONFIGURATION_NOT_FOUND', 404);
                }
                $data += ['hierarchy' => $this->hierarchy->render(), 'rules' => $this->rules->all()];
            } else {
                throw new AccessException('SCREEN_NOT_FOUND', 404);
            }

            return view('agent-access::'.$screen, $data);
        } catch (AccessException $error) {
            abort($error->status, $error->reasonCode);
        }
    }
}
