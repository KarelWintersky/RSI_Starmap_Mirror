# PATCH_FOR_DCLICK — zoom out по двойному клику

Проблема: клик по пустому месту в звёздной системе (или по пустому месту
на объекте) сразу «вылетал» на карту галактики / в родительскую локацию.
Нужно: одинарный клик по пустому месту ничего не делает, возврат — только
по двойному клику (окно 400 мс).

## Причина

Выход осуществляется по ДВУМ независимым путям, поэтому одного патча мало:

1. **controlAPI (`defaultSelectNothing`)** — обработчик события `selectNothing`
   движка. Одиночный клик → `gotoGalacticView()` (в системе) или
   `gotoSystemView()` (на объекте). Это цель, которую видит state machine.
2. **Старый API (`_onSelectNone` через CustomEvent `select:none`)** — старый
   API переизлучает `selectNothing` в DOM-CustomEvent `select:none`, который
   слушает View. При скрытом диске вызывается `goToParentLocation()` →
   `gotoGalacticView()` — выход на один клик, **в обход** патча №1.

Оба пути вызываются в рамках одного клика (двух событийных систем движка),
поэтому нужно патчить оба.

## Патчи

Определены в `src/StarmapGrabber.php::patchBundle()` (применяются при
`php grab.php build`, идемпотентно — только если исходная строка ещё на месте).

### Патч 1: `defaultSelectNothing` (controlAPI)

До:
```js
this.defaultSelectNothing=function(e){t.goal.isGalacticView&&2===e.button?t.releaseSystemPivot():t.goal.isPlanetaryView?t.gotoSystemView().setSystemCode(t.goal.systemCode):t.gotoGalacticView()}
```

После:
```js
this.defaultSelectNothing=function(e){if(t.goal.isGalacticView&&2===e.button)t.releaseSystemPivot();else if(t.goal.isGalacticView)t.gotoGalacticView();else{var n=Date.now();t._dblClickTime&&n-t._dblClickTime<400?(t._dblClickTime=0,t.goal.isPlanetaryView?t.gotoSystemView().setSystemCode(t.goal.systemCode):t.gotoGalacticView()):t._dblClickTime=n}}
```

Логика: в системе/на объекте клик лишь запоминает `t._dblClickTime`;
навигация происходит, только если следующий клик уложился в 400 мс.

### Патч 2: `_onSelectNone` (View, путь через `select:none`)

До:
```js
{key:"_onSelectNone",value:function(t){t.detail.mouseButton===m.default.Mouse.LEFT_BUTTON?this._disc.hidden?this.model.goToParentLocation(this.model.get("location")):(this._disc.hidden=!0,this._trackingDisc=!1,this.model.unselectLocation()):this._disc.hidden||(this._disc.hidden=!0,this._trackingDisc=!1,this.model.unselectLocation())}}
```

После:
```js
{key:"_onSelectNone",value:function(t){if(t.detail.mouseButton===m.default.Mouse.LEFT_BUTTON){if(this._disc.hidden){var n=Date.now();if(this._noneDblClick&&n-this._noneDblClick<400)this._noneDblClick=0,this.model.goToParentLocation(this.model.get("location"));else this._noneDblClick=n}else this._disc.hidden=!0,this._trackingDisc=!1,this.model.unselectLocation()}else this._disc.hidden||(this._disc.hidden=!0,this._trackingDisc=!1,this.model.unselectLocation())}}
```

Логика: `goToParentLocation()` (уход в родительскую локацию) — только по
двойному клику. Скрытие диска и снятие выделения на одинарный клик сохранены.

## Что НЕ является причиной вылета

- `Uncaught TypeError: can't access property "disc", this[S] is undefined`
  при выборе системы — это отдельный баг: отсутствует ассет
  `web/rsi/static/svg/disc.svg` (бандл грузит диск из
  `window.location.origin + "/rsi/static/svg/disc.svg"`, а `this[S]`
  строится только в `load`-событии `<object>`). Лечится скачиванием файла:
  `php grab.php assets` или вручную.

## Проверка

- `node --check web/static/starmap/starmap.bundle.js` — синтаксис.
- В бандле должны присутствовать маркеры `_dblClickTime` и `_noneDblClick`
  (по одному вхождению).
- Поведение: одинарный клик по пустому месту в системе — ничего; двойной
  клик — галактика; двойной клик с объекта — возврат в систему.
