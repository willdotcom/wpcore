<!DOCTYPE html>
<html>
<head>
    <title>Descargar y Administrar Versiones</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/flowbite/1.8.0/flowbite.min.css" rel="stylesheet" />
    <link rel="preconnect" href="https://rsms.me/">
    <link rel="stylesheet" href="https://rsms.me/inter/inter.css">
    <style>
    /* CSS */
        :root { font-family: 'Inter', sans-serif; }
        @supports (font-variation-settings: normal) {
          :root { font-family: 'Inter var', sans-serif; }
        }
        .terminal {
            background-color: black;
            color: limegreen;
            font-family: monospace;
            width: 100%;
            height: 100%;
            padding: 10px;
            overflow-y: scroll;
        }
        .loading {
            display: none;
        }
            #download-button:disabled {
        background-color: #ccc;
        cursor: not-allowed;
    }
    </style>
    <script>
        function scrollToBottom(textarea) {
            textarea.scrollTop = textarea.scrollHeight;
        }

        function toggleLoading(buttonId) {
            var button = document.getElementById(buttonId);
            var loading = document.getElementById(buttonId + '-loading');
            if (button && loading) {
                button.disabled = !button.disabled;
                loading.style.display = loading.style.display === 'none' ? 'block' : 'none';
            }
        }
    </script>
</head>
<body class="bg-gray-100">

<div class="container mx-auto p-8 grid grid-cols-2 gap-8">
    <div class="col-span-1">
        <h2 class="text-2xl font-bold mb-4">SERED WordPress Manager</h2>

        <span class="bg-gray-100 text-gray-800 text-xs font-medium inline-flex items-center px-2.5 py-0.5 rounded mr-2 dark:bg-gray-700 dark:text-gray-400 border border-gray-500">
          <svg class="w-2.5 h-2.5 mr-1.5" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 20 20">
            <path d="M10 0a10 10 0 1 0 10 10A10.011 10.011 0 0 0 10 0Zm3.982 13.982a1 1 0 0 1-1.414 0l-3.274-3.274A1.012 1.012 0 0 1 9 10V6a1 1 0 0 1 2 0v3.586l2.982 2.982a1 1 0 0 1 0 1.414Z"/>
          </svg>
          Plugin Version: 1.0
        </span>
         


        <form action="" method="post" class="mb-8 mt-5">
            <label for="host" class="block mb-2 text-bold">Dominio:</label>
            <input type="text" name="host" value="<?php echo $_SERVER['HTTP_HOST']; ?>" readonly
                class="w-full mb-4 p-2 border rounded">
                    <span class="bg-blue-100 text-blue-800 text-sm font-medium inline-flex items-center px-2.5 py-0.5 rounded dark:bg-gray-700 dark:text-blue-400 border border-blue-400">
                      <svg class="w-2.5 h-2.5 mr-1.5" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 20 20">
                        <path d="M10 0a10 10 0 1 0 10 10A10.011 10.011 0 0 0 10 0Zm3.982 13.982a1 1 0 0 1-1.414 0l-3.274-3.274A1.012 1.012 0 0 1 9 10V6a1 1 0 0 1 2 0v3.586l2.982 2.982a1 1 0 0 1 0 1.414Z"/>
                      </svg>
                      <?php echo " Versión actual de WordPress: <span class='px-1  font-medium text-gray-900 dark:text-white'>" . getWordPressVersion() . "</span> \n"; ?>
                    </span>
            <div class="mb-4 mt-4">
                
                <button type="submit" name="check-version" id="check-version"
                        class="py-2 px-4 bg-green-500 text-white rounded hover:bg-blue-600 inline-flex">
                    Chequear Versión Actual
                </button>
                <button type="submit" name="delete"
                    class="py-2 px-4 bg-red-500 text-white rounded hover:bg-red-600 inline-flex">
                    Eliminar Archivos del core
                </button>
                <div id="check-version-loading" class="loading">
                    <div class="lds-dual-ring inline-block"></div>
                </div>
            </div>

            <!--button type="submit" name="delete"
                    class="py-2 px-4 bg-red-500 text-white rounded hover:bg-red-600">
                Eliminar Archivos del core de Wordpress
            </button-->

            <label for="version" class="block mt-4 mb-2">Seleccionar versión:</label>
            <input type="text" name="version" id="version-input" placeholder="Ejemplo: 6.1.2" class="w-full p-2 border rounded">

            <button type="submit" name="download" id="download-button"
                    class="mt-4 py-2 px-4 bg-blue-500 text-white rounded hover:bg-blue-600" disabled>
                Descargar versión y Extraer
            </button>
            
            <div class="mt-4" id="error-message" role="alert"></div>
            
        </form>
                
        <h2 class="mb-2 text-lg font-semibold text-gray-900 dark:text-white">Estado del proceso:</h2>
        <ul class="max-w-md space-y-2 text-gray-500 list-inside dark:text-gray-400">
            <li class="flex items-center">
                <svg class="w-4 h-4 mr-2 text-green-500 dark:text-green-400 flex-shrink-0" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M10 .5a9.5 9.5 0 1 0 9.5 9.5A9.51 9.51 0 0 0 10 .5Zm3.707 8.207-4 4a1 1 0 0 1-1.414 0l-2-2a1 1 0 0 1 1.414-1.414L9 10.586l3.293-3.293a1 1 0 0 1 1.414 1.414Z"/>
                </svg>
                Eliminado de archivos viejos del Core WordPress
            </li>
            <li class="flex items-center">
                <svg class="w-4 h-4 mr-2 text-green-500 dark:text-green-400 flex-shrink-0" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M10 .5a9.5 9.5 0 1 0 9.5 9.5A9.51 9.51 0 0 0 10 .5Zm3.707 8.207-4 4a1 1 0 0 1-1.414 0l-2-2a1 1 0 0 1 1.414-1.414L9 10.586l3.293-3.293a1 1 0 0 1 1.414 1.414Z"/>
                </svg>
                Descargando La version seleccionada
            </li>
            <li class="flex items-center">
                <div role="status">
                    <svg aria-hidden="true" class="w-4 h-4 mr-2 text-gray-200 animate-spin dark:text-gray-600 fill-blue-600" viewBox="0 0 100 101" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M100 50.5908C100 78.2051 77.6142 100.591 50 100.591C22.3858 100.591 0 78.2051 0 50.5908C0 22.9766 22.3858 0.59082 50 0.59082C77.6142 0.59082 100 22.9766 100 50.5908ZM9.08144 50.5908C9.08144 73.1895 27.4013 91.5094 50 91.5094C72.5987 91.5094 90.9186 73.1895 90.9186 50.5908C90.9186 27.9921 72.5987 9.67226 50 9.67226C27.4013 9.67226 9.08144 27.9921 9.08144 50.5908Z" fill="currentColor"/><path d="M93.9676 39.0409C96.393 38.4038 97.8624 35.9116 97.0079 33.5539C95.2932 28.8227 92.871 24.3692 89.8167 20.348C85.8452 15.1192 80.8826 10.7238 75.2124 7.41289C69.5422 4.10194 63.2754 1.94025 56.7698 1.05124C51.7666 0.367541 46.6976 0.446843 41.7345 1.27873C39.2613 1.69328 37.813 4.19778 38.4501 6.62326C39.0873 9.04874 41.5694 10.4717 44.0505 10.1071C47.8511 9.54855 51.7191 9.52689 55.5402 10.0491C60.8642 10.7766 65.9928 12.5457 70.6331 15.2552C75.2735 17.9648 79.3347 21.5619 82.5849 25.841C84.9175 28.9121 86.7997 32.2913 88.1811 35.8758C89.083 38.2158 91.5421 39.6781 93.9676 39.0409Z" fill="currentFill"/></svg>
                    <span class="sr-only">Loading...</span>
                </div>
                Extrayendo los archivos
            </li>
        </ul>


<script>
    var versionInput = document.getElementById('version-input');
    var downloadButton = document.getElementById('download-button');

    versionInput.addEventListener('input', function() {
        if (versionInput.value.trim() !== '') {
            downloadButton.removeAttribute('disabled');
        } else {
            downloadButton.setAttribute('disabled', 'disabled');
        }
    });
</script>




    </div>

    <div class="col-span-1">
        <textarea id="log" row="4" class="terminal block p-2.5 w-full h-full text-sm text-gray-900 bg-gray-50 rounded-lg border border-gray-300 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500" readonly>
        <?php
        function getCurrentTimestamp() {
            return '[' . date('Y-m-d H:i:s') . ']';
        }

        function getWordPressVersion() {
            $versionFile = __DIR__ . '/wp-includes/version.php';
            if (file_exists($versionFile)) {
                $versionContent = file_get_contents($versionFile);
                if (preg_match('/\$wp_version\s*=\s*\'([^\']+)\'/', $versionContent, $matches)) {
                    return $matches[1];
                }
            }
            return 'No se pudo obtener la versión';
        }

        if (isset($_POST['check-version'])) {
            echo getCurrentTimestamp() . " Versión actual de WordPress: " . getWordPressVersion() . "\n";
        }

        if (isset($_POST['download'])) {
            $selectedVersion = $_POST['version'];
            $zipUrl = "https://wordpress.org/wordpress-{$selectedVersion}-no-content.zip";  // Corregir la construcción de la URL
            $zipFile = "wordpress-{$selectedVersion}-no-content.zip";  // Mantén el nombre correcto aquí


            $destination = __DIR__;
            echo getCurrentTimestamp() . " Descargando {$selectedVersion}...\n";

            $downloadedFile = file_put_contents($zipFile, fopen($zipUrl, 'r'));

            if ($downloadedFile !== false) {
                echo getCurrentTimestamp() . " Archivo descargado exitosamente.\n";

                $zip = new ZipArchive;
                if ($zip->open($zipFile) === TRUE) {
                    echo getCurrentTimestamp() . " Extrayendo archivos y directorios...\n";
                    $zip->extractTo($destination);
                    $zip->close();
                    unlink($zipFile);

                    $subDir = "{$destination}/wordpress";
                    $files = scandir($subDir);
                    foreach ($files as $file) {
                        if ($file != "." && $file != "..") {
                            $fileOrDir = is_dir("{$subDir}/{$file}") ? "Directorio" : "Archivo";
                            echo getCurrentTimestamp() . " --- {$fileOrDir}: {$file}\n";
                            rename("{$subDir}/{$file}", "{$destination}/{$file}");
                        }
                    }
                    rmdir($subDir);

                    echo getCurrentTimestamp() . " Archivos y directorios extraídos y movidos exitosamente.\n";
                } else {
                    echo getCurrentTimestamp() . " Error al extraer los archivos.\n";
                }
            } else {
                echo getCurrentTimestamp() . " Error al descargar el archivo ZIP.\n";
            }
        }

        if (isset($_POST['delete'])) {
            $directoriesToDelete = ['wp-includes', 'wp-admin'];
            foreach ($directoriesToDelete as $dir) {
                deleteDirectory($dir);
            }
            echo getCurrentTimestamp() . " Directorios eliminados exitosamente.\n";

            $filesToDelete = ['license.txt', 'readme.html'];
            foreach ($filesToDelete as $file) {
                if ($file !== 'wp-config.php' && $file !== 'run.php' && file_exists($file)) {
                    unlink($file);
                    echo getCurrentTimestamp() . " Archivo eliminado: {$file}\n";
                }
            }

            $phpFiles = glob("*.php");
            foreach ($phpFiles as $file) {
                if ($file !== "wp-config.php" && $file !== "run.php") {
                    unlink($file);
                    echo getCurrentTimestamp() . " Archivo eliminado: {$file}\n";
                }
            }
            echo getCurrentTimestamp() . " Archivos eliminados exitosamente.\n";
        }

        function deleteDirectory($dir) {
            if (is_dir($dir)) {
                $files = glob("$dir/*");
                foreach ($files as $file) {
                    is_dir($file) ? deleteDirectory($file) : unlink($file);
                    echo getCurrentTimestamp() . " Archivo eliminado: {$file}\n";
                }
                rmdir($dir);
                echo getCurrentTimestamp() . " Directorio eliminado: {$dir}\n";
            }
        }
        ?>
        </textarea>
    </div>
</div>

<script>
    var logTextarea = document.getElementById('log');
    scrollToBottom(logTextarea);
</script>

</body>
</html>