# Скрипт массово добавляет / удаляет Redirect Rules конкретным доменам на CloudFlare.
----------------------------------------------------------------------------------------

1. В файле "domains.txt" список всех доменов (без https://), которым надо установить правила.

2. В файле "cf_accounts.txt" список аккаунтов CloudFlare, где искать эти домены, в формате:
account@email;global_key;rules_token

3. Правило, которое надо установить - в файле index.php в функции "function rules( $domain )".
Можно и не одно правило поставить, но надо аккуратно смотреть синтаксис.

4. Скрипт многопоточный, количество потоков в переменной $_['threads_num'] = 40;
Но, лучше не ставить больше 40 потоков, с одного ip много не даст делать. Если будет много ошибок, то меньше поставить.

----------------------------------------------------------------------------------------
Принцип работы:

1. Сначала со всех аккаунтов CloudFlare экспортируются многопоточно все домены в папку /export/ в файлы.

2. Из общего списка доменов "domains.txt" выбираются домены конкретного аккаунта и складываются в файлы в папку /todo/.

3. Берутся списки с нужными доменами каждого аккаунта из /todo/ и многопоточно пачками по 40 доменов ставятся правила по API.

----------------------------------------------------------------------------------------
Как запускать:

Скрипт лучше запускать либо в терминале на сервере, либо в браузере по ip адресу, либо на localhost, 
так как прямо на домене CloudFlare обрубит соединение такому скрипту через 60 секунд.

1. В браузере по ip:
http://123.123.12.12/cloudflare-rules/index.php

2. Из консоли на сервере:
/usr/bin/php /home/www/cloudflare-rules/index.php

3. На каком-то сервере на localhost:
http://localhost/cloudflare-rules/index.php

----------------------------------------------------------------------------------------
Есть несколько режимов работы:

1. Добавление правил всем доменам из файла "domains.txt" с самого начала:

/cloudflare-rules/index.php

2. Продолжение добавления, если на предыдущем запуске произошла ошибка.
Если 1й режим вылетит с какими-то ошибками посреди работы, можно будет продолжить, так как очередь не удаляется до следующего запуска и хранится в файлах /todo/.

/cloudflare-rules/index.php?next

3. Удаление правил всем доменам из файла "domains.txt" с самого начала:

/cloudflare-rules/index.php?del

4. Продолжение удаления, если на предыдущем запуске удаления произошла ошибка.
Если 3й режим /index.php?del вылетит с какими-то ошибками посреди работы, можно будет продолжить, так как очередь не удаляется до следующего запуска и хранится в файлах /todo/.

/cloudflare-rules/index.php?delnext

-----------------
Режимы с "next" будут работать, только если запускаются после удачно прошедшего экспорта всех доменов из CloudFlare.
Лучше пользоваться основными вариантами без "next", если скрипт не выдает ошибок.
Даже, если будут какие-то ошибки, с ними можно разобраться и повторно запускать основные режимы /index.php, 
это нормально, скрипт будет правила доменам устанавливать поверх, дубликатов правил не будет.

----------------------------------------------------------------------------------------
Лучше работать с аккаунтами CloudFlare, где меньше 1000 доменов уже в аккаунте.
Так как у API есть лимит на 1200 запросов за 5 минут, и потом надо делать перерыв 5 минут.
Этой логики подсчета запросов и лимитов в скрипте нет.
Если будет такая ситуация, то надо будет запускать скрипт частями: сначала /index.php до ошибок, потом /index.php?next до ошибок, перерыв 5 минут, /index.php?next ....
Либо потом надо будет дописать этот функционал.

----------------------------------------------------------------------------------------
В файле cloudflare-rules-function.php есть отдельная функция для установки этих правил, чтобы в свои другие скрипты вставлять для работы с CloudFlare API.
