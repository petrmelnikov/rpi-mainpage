<?php

namespace App\Controller;

use App\HostedToolsManager;
use App\Router;

class ToolsController
{
    public function registerRoutes(Router $router, string $appRoot): void
    {
        $router->addRoute('GET', '/tools', [$this, 'tools'], $appRoot . '/templates/tools.html.php');
        $router->addRoute('POST', '/tools/upload', [$this, 'upload']);
        $router->addRoute('POST', '/tools/delete', [$this, 'delete']);
    }

    public function tools(): array
    {
        $manager = new HostedToolsManager();

        return [
            'hostedTools' => $manager->listTools(),
            'successMessage' => trim((string)($_GET['success'] ?? '')),
            'errorMessage' => trim((string)($_GET['error'] ?? '')),
            'maxUploadBytes' => HostedToolsManager::MAX_UPLOAD_BYTES,
        ];
    }

    public function upload(): string
    {
        try {
            $file = $_FILES['tool_file'] ?? null;
            if (!is_array($file)) {
                throw new \RuntimeException('Выберите HTML-файл или ZIP-архив.');
            }

            $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($uploadError !== UPLOAD_ERR_OK) {
                throw new \RuntimeException($this->uploadErrorMessage($uploadError));
            }

            $temporaryPath = (string)($file['tmp_name'] ?? '');
            if ($temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
                throw new \RuntimeException('Сервер не распознал файл как корректную загрузку.');
            }

            $tool = (new HostedToolsManager())->install(
                $temporaryPath,
                (string)($file['name'] ?? 'upload'),
                (string)($_POST['tool_name'] ?? '')
            );

            return $this->redirect('/tools', [
                'success' => 'Инструмент «' . $tool['name'] . '» опубликован.',
            ]);
        } catch (\Throwable $error) {
            return $this->redirect('/tools', ['error' => $error->getMessage()]);
        }
    }

    public function delete(): string
    {
        try {
            (new HostedToolsManager())->delete(trim((string)($_POST['tool_id'] ?? '')));
            return $this->redirect('/tools', ['success' => 'Инструмент удалён.']);
        } catch (\Throwable $error) {
            return $this->redirect('/tools', ['error' => $error->getMessage()]);
        }
    }

    private function redirect(string $path, array $query): string
    {
        $location = $path . '?' . http_build_query($query);
        header('Location: ' . $location, true, 303);
        exit;
    }

    private function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Файл превышает допустимый размер.',
            UPLOAD_ERR_PARTIAL => 'Файл загрузился не полностью. Попробуйте ещё раз.',
            UPLOAD_ERR_NO_FILE => 'Выберите HTML-файл или ZIP-архив.',
            UPLOAD_ERR_NO_TMP_DIR => 'На сервере отсутствует временный каталог для загрузок.',
            UPLOAD_ERR_CANT_WRITE => 'Сервер не смог записать загруженный файл.',
            UPLOAD_ERR_EXTENSION => 'Загрузка остановлена PHP-расширением.',
            default => 'Ошибка загрузки файла (код ' . $error . ').',
        };
    }
}
