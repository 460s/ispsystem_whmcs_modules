<table>
<tr>
<td colspan="5">Addition IPs: {$assignedips}</td>
</tr>
<tr>
<td>
<form method="post" action="clientarea.php?action=productdetails">
	<input type="hidden" name="id" value="{$serviceid}" />
	<input type="hidden" name="modop" value="custom" />
	<input type="hidden" name="a" value="reboot" />
	<input type="submit" value="Reboot Server" />
</form>
</td>
<td>
<form method="post" action="clientarea.php?action=productdetails">
        <input type="hidden" name="id" value="{$serviceid}" />
        <input type="hidden" name="modop" value="custom" />
        <input type="hidden" name="a" value="poweroff" />
        <input type="submit" value="Power off Server" />
</form>
</td>
<td>
<form method="post" action="clientarea.php?action=productdetails">
        <input type="hidden" name="id" value="{$serviceid}" />
        <input type="hidden" name="modop" value="custom" />
        <input type="hidden" name="a" value="poweron" />
        <input type="submit" value="Power on Server" />
</form>
</td>
<td>
<form method="post" action="clientarea.php?action=productdetails">
        <input type="hidden" name="id" value="{$serviceid}" />
        <input type="hidden" name="modop" value="custom" />
        <input type="hidden" name="a" value="networkoff" />
        <input type="submit" value="Server network off" />
</form>
</td>
<td>
<form method="post" action="clientarea.php?action=productdetails">
        <input type="hidden" name="id" value="{$serviceid}" />
        <input type="hidden" name="modop" value="custom" />
        <input type="hidden" name="a" value="networkon" />
        <input type="submit" value="Server network on" />
</form>
</td>
</tr>
</table>
