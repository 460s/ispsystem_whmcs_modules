<table style="width: 100%">
<tr>
<td colspan="5" style="padding-bottom: 10px;">Addition IPs: {$assignedips}</td>
</tr>
<tr>
<td colspan="5" style="padding-bottom: 10px;">
<form action='clientarea.php?action=productdetails' method='post' target='_blank'>
    <input type='hidden' name='id' value="{$serviceid}" />
    <input type='hidden' name='process_dcimgr' value='true' />
    <input type='submit' name='login_dcimgr' value='Login to Control Panel'/>
    <input type='submit' name='login_ipmi' value='Login to IPMI'/>
</form>
</td>
</tr>    
</table>
