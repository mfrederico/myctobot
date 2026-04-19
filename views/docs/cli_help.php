<h2>CLI Command Reference</h2>

<h3>Basic Usage</h3>
<pre><code>php public/index.php [options]</code></pre>

<h3>Options</h3>
<table class="table">
    <thead>
        <tr>
            <th>Option</th>
            <th>Description</th>
            <th>Example</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><code>--help</code></td>
            <td>Show help message</td>
            <td><code>php index.php --help</code></td>
        </tr>
        <tr>
            <td><code>--control=NAME</code></td>
            <td>Controller name (required)</td>
            <td><code>--control=test</code></td>
        </tr>
        <tr>
            <td><code>--method=NAME</code></td>
            <td>Method name (default: index)</td>
            <td><code>--method=cleanup</code></td>
        </tr>
        <tr>
            <td><code>--member=ID</code></td>
            <td>Member ID to run as</td>
            <td><code>--member=1</code></td>
        </tr>
        <tr>
            <td><code>--params=STRING</code></td>
            <td>URL-encoded parameters</td>
            <td><code>--params='id=5&type=pdf'</code></td>
        </tr>
        <tr>
            <td><code>--json=JSON</code></td>
            <td>JSON parameters</td>
            <td><code>--json='{"key":"value"}'</code></td>
        </tr>
        <tr>
            <td><code>--cron</code></td>
            <td>Cron mode (suppress output)</td>
            <td><code>--cron</code></td>
        </tr>
        <tr>
            <td><code>--verbose</code></td>
            <td>Verbose output</td>
            <td><code>--verbose</code></td>
        </tr>
    </tbody>
</table>

<h3>Examples</h3>

<h4>Run a simple command</h4>
<pre><code>php public/index.php --control=test --method=hello</code></pre>

<h4>Run with parameters</h4>
<pre><code>php public/index.php --control=report --method=generate --params='type=daily&format=pdf'</code></pre>

<h4>Run as specific member</h4>
<pre><code>php public/index.php --member=1 --control=admin --method=cleanup</code></pre>

<h4>Cron job example</h4>
<pre><code>0 2 * * * /usr/bin/php /path/to/index.php --control=cleanup --method=daily --member=1 --cron</code></pre>
