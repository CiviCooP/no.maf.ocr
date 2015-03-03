<div class="crm-block crm-form-block crm-event-ticket-form-block">
  <div class="view-content">
	<div id="help">
	  {ts}This will generate an OCR file of payments pending between the specified start and end dates. The default is from the 15th of next month to the 14th of the following month.{/ts}
	</div>
  </div>
  <table class="form-layout" style="margin:10px 0 10px 0; border-top:1px solid #ccc; border-bottom:1px solid #ccc;">
    <tr>
	  <td class="label" style="width:80px;">{$form.start_date.label}</td>
	  <td>{include file="CRM/common/jcalendar.tpl" elementName=start_date}</td>
	</tr>
    <tr>
      <td class="label" style="width:80px;">{$form.end_date.label}</td>
      <td>{include file="CRM/common/jcalendar.tpl" elementName=end_date}</td>
    </tr>
    {if $form.debug}
    <tr>
      <td class="label" style="width:80px; vertical-align:middle;">{$form.debug.label}</span></td>
      <td>{$form.debug.html}</td>
    </tr>
    {/if}
  </table>
  <div class="crm-submit-buttons">{include file="CRM/common/formButtons.tpl" location="bottom"}</div>
</div>