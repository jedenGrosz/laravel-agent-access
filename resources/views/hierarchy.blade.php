@extends('agent-access::layout')
@section('title', __('Hierarchy & rules'))
@section('content')
<h1>{{ __('Hierarchy & rules') }}</h1><p class="muted">{{ __('Hierarchy orders potential approvers. Only a complete rule authorizes granting access.') }}</p>
<div class="card"><h2>{{ __('Role hierarchy') }}</h2><pre>{{ implode("\n", $hierarchy) ?: __('No hierarchy configured.') }}</pre></div>
@foreach($rules as $rule)<div class="card"><h2>{{ $rule->id }}</h2><dl><dt>{{ __('Permission') }}</dt><dd>{{ $rule->permission }}</dd><dt>{{ __('Grantors') }}</dt><dd>{{ implode(', ', $rule->grantorRoles) }}</dd><dt>{{ __('Recipients') }}</dt><dd>{{ implode(', ', $rule->targetRoles) }}</dd><dt>{{ __('Maximum duration') }}</dt><dd>{{ $rule->maximumDuration }} s</dd><dt>{{ __('Maximum uses') }}</dt><dd>{{ $rule->maximumUses ?? __('Unlimited') }}</dd><dt>{{ __('Resource required') }}</dt><dd>{{ $rule->resourceRequired ? __('Yes') : __('No') }}</dd><dt>{{ __('Bound arguments') }}</dt><dd>{{ implode(', ', $rule->boundArguments) ?: '—' }}</dd></dl></div>@endforeach
@endsection
