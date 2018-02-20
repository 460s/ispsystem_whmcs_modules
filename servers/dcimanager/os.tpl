<p>WARNING! All data on server will be lost. Please get backup, first.</p>
<form method="post" action="clientarea.php?action=productdetails">
        <input type="hidden" name="id" value="{$serviceid}" />
        <input type="hidden" name="modop" value="custom" />
        <input type="hidden" name="a" value="reinstall" />
	<input type="hidden" name="processaction" value="on" />
	<p>New password:<input type="password" name="passwd"/></p>
        <input type="submit" value="Reinstall OS" />
</form>
