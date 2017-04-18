<p><b>OS reinstallation</b></p>
<form method="post" action="clientarea.php?action=productdetails">
        <input type="hidden" name="id" value="{$serviceid}" />
        <input type="hidden" name="modop" value="custom" />
        <input type="hidden" name="a" value="reinstall" />
	<input type="hidden" name="reinstallation" value="on" />
	<p>New password:<input type="password" name="passwd"/></p>
        <input type="submit" value="OK" />
</form>

