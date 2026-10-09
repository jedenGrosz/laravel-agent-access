@extends('agent-access::layout')
@section('title', __('Request details'))
@section('content')
<h1>{{ $record->action }}</h1><p class="muted">{{ __('Request') }} <code>{{ $record->id }}</code></p>
<div class="card"><span class="badge">{{ $record->status }}</span><dl>
<dt>{{ __('Recipient') }}</dt><dd>{{ $record->principal_id }}</dd><dt>{{ __('Permission') }}</dt><dd>{{ $record->permission }}</dd>
<dt>{{ __('Resource') }}</dt><dd>{{ $record->resource_type ?? '—' }} {{ $record->resource_id }}</dd><dt>{{ __('Grant rule') }}</dt><dd>{{ $record->rule_id }}</dd>
<dt>{{ __('Duration') }}</dt><dd>{{ $record->duration_seconds }} {{ __('seconds from approval') }}</dd><dt>{{ __('Uses') }}</dt><dd>{{ $record->max_uses ?? __('Unlimited') }}</dd>
<dt>{{ __('Request expires') }}</dt><dd>{{ $record->expires_at->toIso8601String() }}</dd></dl>
<h2>{{ __('Bound arguments') }}</h2><pre>{{ json_encode($record->bound_arguments, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
<h2>{{ __('Reason supplied by requester') }}</h2><p>{{ $record->reason ?: __('No reason supplied.') }}</p></div>
@if($canApprove)<div class="card"><h2>{{ __('Decision') }}</h2><p class="muted">{{ __('The current roles, conditions, IAM and rule revision will be checked again when you submit.') }}</p>
<div class="actions"><form method="post" action="{{ route('agent-access.panel.approve',['id'=>$record->id]) }}">@csrf<button>{{ __('Approve this scope') }}</button></form></div>
<form method="post" action="{{ route('agent-access.panel.reject',['id'=>$record->id]) }}">@csrf<label for="reason">{{ __('Rejection reason (optional)') }}</label><textarea name="reason" id="reason" maxlength="500"></textarea><button class="danger">{{ __('Reject request') }}</button></form></div>@endif
@if($canCancel)<form method="post" action="{{ route('agent-access.panel.cancel',['id'=>$record->id]) }}">@csrf<button class="secondary">{{ __('Cancel my request') }}</button></form>@endif
@endsection
