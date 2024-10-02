@php
	// If tabs ids will be same, when tabs loaded second time they will not work
	$tabs_unique = time();
@endphp
<ul class="nav nav-tabs nav-tabs-no-bottom">
	<li role="presentation" class="active"><a data-toggle="tab" href="#tab_preview_{{ $tabs_unique }}">{{ __('Preview') }}</a></li>
	<li role="presentation"><a data-toggle="tab" href="#tab_body_{{ $tabs_unique }}">{{ __('Body') }}</a></li>
	@if ($thread->headers)<li role="presentation"><a data-toggle="tab" href="#tab_headers_{{ $tabs_unique }}">{{ __('Headers') }}</a></li>@endif
</ul>

<div class="tab-content">
	<div id="tab_preview_{{ $tabs_unique }}" class="tab-pane fade in active">
		@if (!$fetched)
			<div class="alert alert-info alert-narrow margin-bottom-0">{{ __('The original message could not be loaded from mail server, below is the latest truncated copy stored in database.') }} (<a href="{{ config('app.freescout_repo') }}/wiki/FAQ#why-does-show-original-window-shows-truncated-message-without-previous-history" target="_blank">{{ __('read more') }}</a>)</div>
		@endif
		<iframe sandbox="" srcdoc="{!! str_replace('"', '&quot;', $body_preview) !!}" frameborder="0" class="preview-iframe tab-body"></iframe>
	</div>
	<div id="tab_body_{{ $tabs_unique }}" class="tab-pane fade">
		<pre class="pre-wrap">{{ $thread->getBodyOriginal() }}</pre>
	</div>
	@if ($thread->headers)<div id="tab_headers_{{ $tabs_unique }}" class="tab-pane fade">
		<pre class="pre-wrap">{{ $thread->headers }}</pre>
	</div>@endif
</div>