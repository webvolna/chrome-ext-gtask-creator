// popup.js — финальная версия
document.getElementById('addTask').addEventListener('click', async () => {
    try {
      // Получаем активную вкладку
      const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  
      // Извлекаем данные со страницы
      const result = await chrome.scripting.executeScript({
        target: { tabId: tab.id },
        func: () => ({
          title: document.title,
          url: location.href,
          selection: window.getSelection().toString()
        })
      });
  
      const data = result[0].result;
      const endpoint = 'https://musthaveapp.na4u.ru/gtask.php';
      const params = new URLSearchParams({
        title: data.title,
        url: data.url,
        selection: data.selection
      });
      const url = `${endpoint}?${params.toString()}`;
  
      // Открываем всплывающее окно с PHP-приложением
      const windowRef = window.open(url, 'gtask_popup', 'width=720,height=600');
  
      if (!windowRef) {
        alert('Разреши всплывающие окна, чтобы добавить задачу.');
        return;
      }
  
      // Слушатель сообщений от gtask.php
      function onMessage(event) {
        const trustedOrigin = 'https://musthaveapp.na4u.ru';
        // При желании включи строгую проверку:
        // if (event.origin !== trustedOrigin) return;
  
        if (event.data === 'task_created') {
          chrome.notifications.create({
            type: 'basic',
            iconUrl: 'icon.png',
            title: '✅ Задача создана',
            message: 'Задача успешно добавлена в Google Tasks.',
            priority: 2
          });
          cleanup();
        } else if (event.data?.type === 'task_error') {
          chrome.notifications.create({
            type: 'basic',
            iconUrl: 'icon.png',
            title: 'Ошибка',
            message: String(event.data.message || 'Не удалось создать задачу'),
            priority: 2
          });
          cleanup();
        }
      }
  
      function cleanup() {
        window.removeEventListener('message', onMessage);
        try { if (windowRef && !windowRef.closed) windowRef.close(); } catch (e) {}
        clearTimeout(timeoutId);
      }
  
      // Добавляем слушатель и таймаут
      window.addEventListener('message', onMessage);
      const timeoutId = setTimeout(() => {
        window.removeEventListener('message', onMessage);
        chrome.notifications.create({
          type: 'basic',
          iconUrl: 'icon.png',
          title: '⏳ Нет ответа',
          message: 'Сервер не ответил. Попробуй снова.',
          priority: 1
        });
      }, 30000);
  
    } catch (err) {
      console.error(err);
      chrome.notifications.create({
        type: 'basic',
        iconUrl: 'icon.png',
        title: 'Ошибка расширения',
        message: err.message || String(err),
        priority: 2
      });
    }
  });
  