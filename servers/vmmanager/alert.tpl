<p><b>Attention!</b></p>
<p>Are you sure you want to {$description}?</b>
<form method="post" action="clientarea.php?action=productdetails">
        <input type="hidden" name="id" value="{$serviceid}" />
        <input type="hidden" name="modop" value="custom" />
        <input type="hidden" name="a" value="{$action}" />
        <input type="submit" name="yes" value="Yes" />
        <input type="submit" name="abort" value="No" />
</form>

