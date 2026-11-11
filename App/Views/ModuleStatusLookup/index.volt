{#
  Status Lookup page shell. No settings, records or caller data are rendered
  here: the script loads them as JSON and inserts them as text only. Only
  translations go through Volt, escaped with |e.
#}
<div id="status-lookup" class="sl-page">

    <div class="ui negative message sl-hidden" id="sl-error" role="alert"></div>

    <div class="ui top attached tabular menu" id="sl-tabs">
        <div class="right menu">
            <a class="item" data-tab="callflow"><i class="cog icon"></i>{{ t._('sl_TabCallFlow')|e }}</a>
        </div>
    </div>

    <div class="ui bottom attached tab segment" data-tab="callflow">
        <div id="sl-callflow"></div>
    </div>

    <div class="ui small modal" id="sl-confirm-modal">
        <div class="header" id="sl-confirm-title"></div>
        <div class="content"><p id="sl-confirm-text"></p></div>
        <div class="actions">
            <button class="ui button" type="button" id="sl-confirm-cancel">{{ t._('sl_Cancel')|e }}</button>
            <button class="ui red button" type="button" id="sl-confirm-ok"></button>
        </div>
    </div>
</div>
