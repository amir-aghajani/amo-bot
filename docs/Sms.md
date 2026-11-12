[فارسی](fa/Sms.md)

# SMS

The shop sends one kind of SMS: a one-time code — the code a website's visitor types to sign in by their phone number
(«ورود با شماره موبایل», [Website](Website.md)). Nothing else goes by SMS: the shop's notices go by Telegram, and by
email to customers Telegram cannot reach. It is off until you set it up in «تنظیمات پنل › پیامک»
([Panel settings](Panel-Settings.md#sms)); what you set is kept in `config.php` (`SMS_*`,
[config.php reference](Config-Reference.md#sms)), so it is the whole installation's: one SMS account for every shop.

## How a code goes out

The shop sends **the code alone**. The words around it are a **pattern** («الگو», «پترن») you write in the SMS
provider's panel and the provider approves, with one variable where the code goes — for example
«کد ورود شما به فروشگاه امو: %code%», your shop's name written into it as text. A pattern goes from a service line: it
reaches numbers that have blocked advertising SMS, and it goes at once — the way one-time codes are sent in Iran.

The first provider is **IPPanel** (ippanel.com), through its Edge API.

## Setting IPPanel up

1. **An account** at IPPanel, with credit: every SMS is charged from it, in Rial.
2. **The access key** — in IPPanel's panel, «توسعه‌دهندگان ← کلیدهای دسترسی» (Developers › Access Keys): make one and
   copy it whole. The shop sends it to IPPanel's address alone, as it is.
3. **The sender line** — one of your account's lines, as IPPanel's panel lists them: `3000505` or `+983000505` (the
   shop keeps it as IPPanel takes it, `+983000505`). A line that is not your account's is refused.
4. **The pattern** — in IPPanel's panel, make a pattern for the sign-in code with **one variable**, the code
   (`%code%` above), and wait for IPPanel to approve it. Copy its **code** — the id IPPanel shows beside the pattern. A
   pattern with a second variable is refused when a code is sent: write everything else into its text. The code is
   digits and may begin with 0: if IPPanel asks the variable's type, a text type keeps such a code whole.
5. **«تنظیمات پنل › پیامک»** — «روش ارسال»: «IPPanel (ای‌پی پنل)»; then «کلید دسترسی», «شماره فرستنده», «کد الگو» and
   «نام متغیر کد» (`code` for `%code%`; typed with its `%` or without). «آدرس API», under «تنظیمات پیشرفته», stays
   `https://edge.ippanel.com/v1` unless IPPanel gives you another; the key kept is not sent to another address — moving
   it asks for the key again. Save.
6. **The test** — card «ارسال پیامک آزمایشی»: send a test code to your own phone. The screen shows the code it sent;
   the SMS should bring the same, in the pattern's words. Above the field, «اعتبار حساب» shows the account's credit as
   IPPanel tells it, in Rial — IPPanel answering it is the key working.

## When a code does not go

The test send and the credit line say what IPPanel said:

- «IPPanel کلید دسترسی را نپذیرفت …» — the key is wrong, deleted or not an access key: make a new one and save it.
- «IPPanel درخواست را نپذیرفت: …» — IPPanel refused a part of the request, each part in its words: the line, the
  pattern's code or its variable.
- «IPPanel نپذیرفت: …» — anything else IPPanel said no to: no credit left, a pattern not approved (yet), a line not the
  account's.
- «IPPanel به‌موقع جواب نداد.», «به IPPanel وصل نشد: …» — IPPanel out of reach from the host; the shop waits 15 seconds
  at most, as a visitor waits for their code.
- «ارسال پیامک هنوز راه نیفتاده است؛ اول تنظیمات را ذخیره کنید.» — no way, or not its settings, saved yet.

A visitor reads only «پیامک فرستاده نشد؛ کمی بعد دوباره امتحان کنید.». The access key is in nothing the shop shows or
logs.

## Agents' websites

The switch «سایت نماینده‌ها هم بتوانند کد ورود پیامکی بفرستند» (`SMS_AGENTS`, off): while it is off, only the main
shop's website offers sign-in by phone number; on, the agents' websites do too. There is one SMS account, yours: **every
code an agent's website sends is paid from your credit**. Turn it on only when that is fine with you.

## Costs

Every code is one pattern SMS, charged from the account's credit at IPPanel's price — the test codes too. The shop
sends nothing else by SMS. Keep an eye on «اعتبار حساب»: with no credit left, no visitor can sign in by phone number.

## For developers

SMS is a driver family ([Drivers](Drivers.md#an-sms-driver)): `Core\Sms\SmsDriver`, registered in `sms.drivers`
(`bootstrap/container.php`), IPPanel's in `app/Core/Sms/Drivers/`. The sender `config.php` names is the container's
`sms.sender`, and `Core\Sms\Sms` is what the rest of the shop asks: `available()` (a way is set up), `forAgents()`
(`SMS_AGENTS`) and `sendCode($to, $code)`, which throws `SmsFailedException` — a 502 that tells a customer only that the
code did not go, its `reason` the provider's words.
