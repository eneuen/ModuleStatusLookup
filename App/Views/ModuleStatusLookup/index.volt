{#
  Status Lookup page shell. No settings, records or caller data are rendered
  here: the script loads them as JSON and inserts them as text only. Only
  translations go through Volt, escaped with |e.
#}
<div id="status-lookup" class="sl-page">

    <div class="ui negative message sl-hidden" id="sl-error" role="alert"></div>

    <div class="ui top attached tabular menu" id="sl-tabs">
        <a class="item" data-tab="records">{{ t._('sl_TabRecords')|e }}</a>
        <a class="item" data-tab="import">{{ t._('sl_TabImport')|e }}</a>
        <a class="item" data-tab="statuses">{{ t._('sl_TabStatuses')|e }}</a>
        <div class="right menu">
            <a class="item" data-tab="callflow"><i class="cog icon"></i>{{ t._('sl_TabCallFlow')|e }}</a>
        </div>
    </div>

    <div class="ui bottom attached tab segment" data-tab="records">
        <div id="sl-records"></div>
    </div>

    <div class="ui bottom attached tab segment" data-tab="import">
        <div id="sl-import"></div>
    </div>

    <div class="ui bottom attached tab segment" data-tab="statuses">
        <div id="sl-statuses"></div>
    </div>

    <div class="ui bottom attached tab segment" data-tab="callflow">
        <div id="sl-callflow"></div>
    </div>

    <div class="ui small modal" id="sl-status-modal">
        <div class="header" id="sl-status-title"></div>
        <div class="content"><form class="ui form" id="sl-status-form"></form></div>
        <div class="actions">
            <button class="ui button" type="button" id="sl-status-cancel">{{ t._('sl_Cancel')|e }}</button>
            <button class="ui primary button" type="button" id="sl-status-save">{{ t._('sl_SaveStatus')|e }}</button>
        </div>
    </div>

    <div class="ui small modal" id="sl-record-modal">
        <div class="header" id="sl-record-title"></div>
        <div class="content"><form class="ui form" id="sl-record-form"></form></div>
        <div class="actions">
            <button class="ui button" type="button" id="sl-record-cancel">{{ t._('sl_Cancel')|e }}</button>
            <button class="ui primary button" type="button" id="sl-record-save">{{ t._('sl_SaveRecord')|e }}</button>
        </div>
    </div>

    <div class="ui small modal" id="sl-confirm-modal">
        <div class="header" id="sl-confirm-title"></div>
        <div class="content"><p id="sl-confirm-text"></p><div class="ui form" id="sl-confirm-extra"></div></div>
        <div class="actions">
            <button class="ui button" type="button" id="sl-confirm-cancel">{{ t._('sl_Cancel')|e }}</button>
            <button class="ui red button" type="button" id="sl-confirm-ok"></button>
        </div>
    </div>
</div>
