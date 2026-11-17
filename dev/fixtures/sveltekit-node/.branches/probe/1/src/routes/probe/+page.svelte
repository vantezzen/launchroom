<script lang="ts">
	let { data, form } = $props();
</script>

<p id="client">{data.clientAddress}</p>
<p id="url">{data.url}</p>
<form method="POST"><input name="value" value="from the form" /><button>Send</button></form>
{#if form}<p id="echoed">{form.echoed}</p>{/if}
