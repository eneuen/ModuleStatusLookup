{#
  Status Lookup page shell. Only translations and the IVR's number (digits,
  checked by the controller) go through Volt, escaped with |e.
#}
<div id="status-lookup" class="sl-page">
    <div class="ui info message">
        <div class="header">{{ t._('sl_NumberHeader')|e }}</div>
        {% if number != '' %}
            <p>{{ t._('sl_NumberIs')|e }} <strong>{{ number|e }}</strong></p>
        {% else %}
            <p>{{ t._('sl_NoNumber')|e }}</p>
        {% endif %}
        <p>{{ t._('sl_NumberHint')|e }}</p>
    </div>
</div>
