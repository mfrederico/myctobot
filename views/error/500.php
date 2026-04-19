<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($title ?? '500 - Server Error') ?></title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: #f5f5f5;
            <?php if (isset($exception)): ?>
            padding: 2rem;
            margin: 0;
            <?php else: ?>
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
            margin: 0;
            <?php endif; ?>
        }
        .error-container {
            <?php if (isset($exception)): ?>
            max-width: 1200px;
            margin: 0 auto;
            <?php else: ?>
            text-align: center;
            max-width: 500px;
            <?php endif; ?>
            padding: 2rem;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #dc3545;
            font-size: 3rem;
            margin: 0 0 1rem 0;
            <?php if (!isset($exception)): ?>
            text-align: center;
            <?php endif; ?>
        }
        h2 {
            color: #333;
            font-size: 1.5rem;
            margin: 0 0 1rem 0;
        }
        h3 {
            color: #666;
            font-size: 1.2rem;
            margin: 1.5rem 0 0.5rem 0;
            border-bottom: 2px solid #dc3545;
            padding-bottom: 0.3rem;
        }
        p {
            color: #666;
            margin: 1rem 0;
            <?php if (!isset($exception)): ?>
            text-align: center;
            <?php endif; ?>
        }
        .error-details {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 4px;
            margin: 1rem 0;
            text-align: left;
            font-family: monospace;
            font-size: 0.9rem;
            color: #dc3545;
            word-wrap: break-word;
            overflow-x: auto;
        }
        .file-info {
            background: #fff3cd;
            color: #856404;
            padding: 1rem;
            border-radius: 4px;
            margin: 1rem 0;
            border-left: 4px solid #ffc107;
        }
        .trace-frame {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 1rem;
            margin: 0.5rem 0;
            font-family: monospace;
            font-size: 0.85rem;
        }
        .trace-frame:hover {
            background: #e9ecef;
        }
        .frame-number {
            color: #6c757d;
            font-weight: bold;
            margin-right: 0.5rem;
        }
        .frame-file {
            color: #0066cc;
            margin-bottom: 0.5rem;
        }
        .frame-function {
            color: #d73502;
            font-weight: bold;
        }
        .frame-args {
            color: #666;
            margin-left: 1rem;
            margin-top: 0.5rem;
        }
        pre {
            white-space: pre-wrap;
            word-wrap: break-word;
            margin: 0;
        }
        a {
            color: #007bff;
            text-decoration: none;
        }
        a:hover {
            text-decoration: underline;
        }
        .home-link {
            text-align: center;
            margin-top: 2rem;
        }
    </style>
</head>
<body>
    <div class="error-container">
        <h1>500</h1>
        <h2>Server Error</h2>
        
        <?php if (isset($exception)): ?>
            <!-- Development Mode: Show full debug information -->
            <div class="file-info">
                <strong>Exception:</strong> <?= get_class($exception) ?><br>
                <strong>File:</strong> <?= h($file) ?><br>
                <strong>Line:</strong> <?= h($line) ?>
            </div>
            
            <h3>Error Message</h3>
            <div class="error-details">
                <?= h($error) ?>
            </div>
            
            <?php if (isset($exception->getPrevious)): ?>
                <?php $previous = $exception->getPrevious(); ?>
                <?php if ($previous): ?>
                    <h3>Previous Exception</h3>
                    <div class="error-details">
                        <strong><?= get_class($previous) ?>:</strong> <?= h($previous->getMessage()) ?><br>
                        <strong>File:</strong> <?= h($previous->getFile()) ?><br>
                        <strong>Line:</strong> <?= $previous->getLine() ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            
            <h3>Stack Trace</h3>
            <?php if (!empty($trace)): ?>
                <?php foreach ($trace as $index => $frame): ?>
                    <div class="trace-frame">
                        <span class="frame-number">#<?= $index ?></span>
                        
                        <?php if (isset($frame['file'])): ?>
                            <div class="frame-file">
                                <?= h($frame['file']) ?>:<?= $frame['line'] ?? '?' ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (isset($frame['class'])): ?>
                            <span class="frame-function">
                                <?= h($frame['class']) ?><?= h($frame['type'] ?? '::') ?><?= h($frame['function']) ?>()
                            </span>
                        <?php elseif (isset($frame['function'])): ?>
                            <span class="frame-function">
                                <?= h($frame['function']) ?>()
                            </span>
                        <?php endif; ?>
                        
                        <?php if (!empty($frame['args'])): ?>
                            <div class="frame-args">
                                Arguments: <?= h(json_encode($frame['args'], JSON_PRETTY_PRINT)) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php elseif (!empty($traceString)): ?>
                <div class="error-details">
                    <pre><?= h($traceString) ?></pre>
                </div>
            <?php endif; ?>
            
        <?php else: ?>
            <!-- Production Mode: Show minimal error information -->
            <p>Something went wrong on our end. Please try again later.</p>
            
            <?php if (!empty($error) && $error !== 'An error occurred'): ?>
            <div class="error-details">
                <?= h($error) ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>
        
        <div class="home-link">
            <p><a href="/">Go back to homepage</a></p>
        </div>
    </div>
</body>
</html>