# Lucuma Marketplace (plugin WordPress)

Núcleo del marketplace de maquinaria (fase 1, equivalente a Machinery Trader). Outline del proyecto:
Proyectos → Ecosistema Heavy Machinery → Archivos → «Outline del proyecto».

Despliegue: Deployer for Git (archivo principal en la raíz del repo). Tras actualizar, el plugin
refresca solo el rol Dealer y las reglas de URL una vez por versión (`lm_version`).

## Modelo
- `lm_equipment` (REST `/wp/v2/equipment`, URL `/equipment/<slug>/`): un equipo; el autor es el dealer.
- `lm_category` jerárquica (`/for-sale/construction/excavators/`); cada categoría lleva en `lm_specs`
  la lista de especificaciones. Un equipo usa las de su categoría y sus ancestros.
- `lm_make` (`/make/caterpillar/`), `lm_model` (`/model/caterpillar-320/`, meta `lm_make`). Al guardar,
  la marca se alinea con la del modelo.
- Meta: `lm_year`, `lm_hours`, `lm_mileage`, `lm_price`, `lm_call_for_price`, `lm_condition`
  (new/used/refurbished), `lm_status` (available/pending/sold), `lm_stock_number`, `lm_city`,
  `lm_state` (2 letras), `lm_zip` (5 dígitos), `lm_country` (US), `lm_video_url`, `lm_gallery` (ids),
  `lm_specs` (objeto). `lm_serial` es privado: solo lo lee/escribe quien puede editar el equipo.
- Rol `lm_dealer`: crea, publica, edita y borra solo lo suyo; asigna términos pero no edita el catálogo.

## API `lucuma-mkt/v1`
- `GET /catalog`: categorías con sus specs y marcas (público).
- `POST /seed`: carga `data/catalogo.json` (admin; idempotente por slug, pisa las specs).
- `POST /models` `{make, model}`: busca o crea el modelo bajo una marca existente (dealers y admin; la marca acepta alias, p. ej. "Deere").
- `GET /resolve?category=…&make=…`: traduce un nombre (de Sandhills u otro origen) a la categoría o marca del catálogo, por nombre, slug o alias (`lm_aliases`).

## Panel del dealer (0.3.0)
- Página `/dealer/` (shortcode `[lm_dealer_panel]`, se crea sola). App sin dependencias en `assets/panel.js`:
  lista con estado rápido, formulario (categoría → specs, marca → modelos, fotos arrastrando, precio,
  ubicación), perfil de empresa con logo. Publica directo (decisión 2a); "Save as draft" opcional.
- Los dealers no entran al wp-admin ni ven la barra; al iniciar sesión van al panel. Solo ven sus fotos.
- Invitación (decisión 1a): `POST /lucuma-mkt/v1/dealers {email, company, name?, phone?, city?, state?}` →
  enlace para crear contraseña (no depende del correo). También vale Usuarios → Añadir con rol Dealer.
- API del panel: `/my/equipment` (GET/POST), `/my/equipment/{id}` (GET/POST/DELETE),
  `/my/equipment/{id}/status`, `/models?make=`, `/my/profile` (GET/POST).

## Piezas públicas para Bricks (0.4.0)
Shortcodes que Bricks coloca con su elemento Shortcode (diseño en Bricks, lógica aquí; estilos en
`assets/front.css`): `[lm_search]`, `[lm_categories]`, `[lm_makes]`, `[lm_grid count="8"]` (y `main="1"`
en el archivo, con filtros `?q=` `?eqcat=` `?make=` `?dealer=` y paginación), `[lm_filters]`,
`[lm_archive_title]`; en la ficha `[lm_breadcrumbs]` `[lm_price]` `[lm_gallery]` `[lm_facts]` `[lm_specs]`
`[lm_dealer]`. Nunca usar `?cat=` (reservado por WordPress). Vendidos al final del listado.

## Importador (0.5.0)
Panel → Import (`#/import`): plantilla CSV, subir archivo, vista previa por fila (categoría y marca
resueltas con alias; errores marcados) e importación en tandas con barra de avance. Columnas:
`stock_number, category, make, model, year, hours, mileage, price, call_for_price, condition, status,
serial, city, state, zip, title, description, video_url, photos` (+ `spec:<clave>`); acepta alias
(`stock`, `manufacturer`, `vin`, `images`…) y separador `,` o `;`. `photos`: URLs separadas por `|`.
Reimportar actualiza por número de stock (por dealer); las fotos se bajan una vez (`_lm_source_url`).
El admin importa a nombre de un dealer. API: `GET /import/template`, `POST /import` (multipart `file`
o JSON `rows`, `dealer`), `POST /import/{job}/run {limit}`. No lee Sandhills directamente (Cloudflare):
el inventario de un dealer de Sandhills entra como CSV o feed oficial.

## SEO (0.8.0)
`includes/class-lm-seo.php`, encima de SEOPress (sus ajustes generales siguen en SEOPress):
- Títulos y descripciones desde los datos: ficha "2001 Case 1845C Skid Steer for Sale in Carbondale, PA | Heavy Listing"
  (+ precio, horas y dealer en la descripción); categoría "Used Skid Steers for Sale" (raíces: "Construction Equipment",
  "Farm Equipment"…); marca "Used Bobcat Equipment for Sale"; modelo "Used John Deere 1050K for Sale"; archivo
  "Used Heavy Equipment for Sale From Dealers". Filtros `seopress_titles_title` / `seopress_titles_desc`.
- `noindex, follow` (filtro `seopress_titles_robots`) y fuera del sitemap: listados con parámetros (`?q=`, `?eqcat=`…;
  se ignoran `utm_*`, `gclid`, `fbclid`, `msclkid`), marcas y modelos con menos de 2 equipos, categorías vacías, `/dealer/`.
- JSON-LD: Product + Offer (precio USD, disponibilidad según estado, vendedor con dirección) + BreadcrumbList en la
  ficha; Organization + WebSite con SearchAction en la portada. Sin precio ("call for price") no se publica Offer.
- URL de ficha `<título>-<id>` al guardar (única y estable); WordPress redirige el slug viejo. Los equipos existentes
  la reciben al volver a guardarse (un POST REST vacío alcanza).

## Remates (0.9.0)
`includes/class-lm-auctions.php`: calendario de remates (fase A, sin pujas: cada remate enlaza a su catálogo).
- Tipo `lm_auction` (REST `/wp/v2/auctions`, URL `/auctions/<slug>/`, archivo `/auctions/` solo próximos, por fecha;
  filtro `?state=PA`). Meta: `lm_start`/`lm_end` (hora local ET, `YYYY-MM-DDTHH:MM`), `lm_type` (live, online, timed,
  live-online, live-online-phone), `lm_auctioneer` (si el rematador no es dealer; si no, el autor), `lm_address`, `lm_city`, `lm_state`,
  `lm_zip`, `lm_url` (catálogo), `lm_featured` (pagado: Auction plan). Caja "Auction details" en el wp-admin.
- Shortcodes: `[lm_auctions count="6"]` (y `main="1"` en el archivo), `[lm_auctions_featured count="3" min="6" title="…"]` (tarjeta destacada a todo el ancho; las listas siguientes no los repiten; solo con 6+ remates próximos,
  de las próximas 3 semanas, orden al azar en cada visita), `[lm_auction_facts]` (ficha; botón con `data-lm-event="auction_catalog"`).
- SEO (en class-lm-seo.php): título con fecha y lugar, JSON-LD Event (modo presencial/online/mixto), noindex de
  remates terminados y del archivo filtrado.
- Medición (0.9.5): enlaces de remates con `data-lm-event` (`auction_open` en tarjetas, `auction_catalog` en el botón del
  catálogo) y `data-lm-placement` (featured, list, auction_page) + datos del remate; en la ficha, `dataLayer.push` del
  evento `hl_auction_view` (en el footer, después del consentimiento). GTM los convierte en select_auction,
  click_auction_catalog y view_auction.
- Bricks: plantillas "LHM · Auctions archive" y "LHM · Auction single" (`bricks/gen.py`: subastas, subasta).

## Dealers y remates por mes (0.10.0)
- `includes/class-lm-dealers.php`: el archivo de autor pasa a `/dealers/<empresa>/` (author base "dealers"; slug =
  nombre de la empresa, se actualiza solo al cambiar `lm_company`). Solo los dealers tienen perfil (otros autores: 404).
  Shortcodes `[lm_dealers]` (directorio en la página /dealers/) y `[lm_dealer_profile]` (logo, contacto, "Visit website"
  con `data-lm-event="dealer_website"`, remates próximos). En la ficha, "More equipment from this dealer" va al perfil.
- Remates: `/auctions/AAAA-MM/` (query var `lm_month`, incluye pasados del mes), `[lm_auctions_title]` (h1 + intro según
  calendario o mes; "in Pennsylvania" mientras todos sean de PA) y `[lm_auction_months]` (chips de meses).
- SEO: títulos y descripciones para perfiles y meses, JSON-LD Organization del dealer, el vendedor del Product enlaza al
  perfil, noindex de meses sin remates.
- Bricks: plantilla "LHM · Dealer profile" (archiveType author) y página "Dealers" (`bricks/gen.py`: perfil_dealer, distribuidores).

## Blog (0.11.0)
`includes/class-lm-blog.php`: estilo editorial propio (no las tarjetas de equipos). `[lm_blog count="12"]` (en la página
/blog/ el más nuevo va grande; `main="1"` en categorías), `[lm_blog_cats]`, `[lm_blog_title]`, `[lm_post_meta]`
(categoría, fecha, minutos de lectura, autor "Heavy Listing Team"), `[lm_related_posts]`. Cuerpo con clase `lmf-prose`
(tip-box, cta-box, tablas; acepta `[lm_grid]` y `[lm_auctions]` dentro). JSON-LD BlogPosting. Colores de categoría por slug:
auctions, prices, buying-guides, selling.

## Landings regionales (0.14.0)

- `[lm_grid cities="Littlestown, Hanover" count="12"]` y `[lm_auctions cities="..." count="6"]` filtran por el campo `lm_city`
  (lista separada por comas, coincidencia exacta). Sin remates en la zona, `lm_auctions` muestra un aviso con enlace al calendario.
- `[lm_count cities="..." category="..."]` devuelve el número de equipos, para textos como "98 machines near Harrisburg".
- Las ciudades de cada región viven en el shortcode de la página (Bricks, `bricks/gen.py`), no en el plugin.

## Resultados por dealer (0.15.0)

- `LM_Stats` (tabla `{prefix}lm_stats`: día, ítem, dealer, tipo, conteo; sin datos personales). Tipos: `view`, `call`, `email`, `website`, `catalog`.
- Conteo en el navegador con `sendBeacon` a `POST lucuma-mkt/v1/hit` (los bots sin JS no cuentan; filtro de user-agent de bots).
  Vista: 1 por visitante, ítem y día (localStorage). No se imprime el script para dealers ni admins logueados.
  Clics: reutiliza los `data-lm-event` de GTM (`call`, `email`, `dealer_website`/`source` → website, `auction_catalog` → catalog).
- El dueño del ítem sale siempre del post (`post_author`), nunca del navegador. Remates creados por un admin quedan a nombre de ese admin.
- Panel: `#/results` (KPIs + los 10 más vistos, 7/30/90 días). Admin: tabla de todos los dealers y `#/results/ID`.
- REST: `GET my/stats?days=30` (dealer) y `GET stats?days=30[&dealer=ID]` (admin).

## Siguiente (outline, orden de trabajo)
Importador CSV/Sandhills · tabla propia de búsqueda + buscador ·
contactos y medición · páginas SEO de categoría/marca/modelo · widget para la web de Kurtz.

## 0.20.0: remates en vivo de Heavy Listing Live (15-nov-2026)

- Los remates que un dealer arma en Yard llegan solos al calendario: `POST lucuma-mkt/v1/sync/auction` (firmado como el resto
  de la sincronización) crea o actualiza la ficha `lm_auction` a nombre del dealer, por `lm_live_id`. Sin fecha queda en
  borrador; borrado en Yard pasa a borrador. Metas nuevas: `lm_live` (slug en Live), `lm_live_id`, `lm_image` (foto de Yard
  cuando no hay destacada).
- En la ficha, `[lm_auction_facts]` muestra el remate en vivo para mirar y pujar sin salir del sitio (`LM_Live`, shortcode
  `[lm_live]`): iframe de `live.heavylisting.com/a/<slug>?embed=1` (mismo sitio, así la sesión del comprador funciona sin
  cookies de terceros), con altura automática. El botón de las tarjetas pasa a "Watch & bid live" (a la misma ficha).
- "Live now": las tarjetas con `data-lm-live` se marcan solas con la lista pública de Live.
- GTM: `live_view`, `bidder_register` y `bid_placed` (con `value` en USD), con `auction_id` y `auction_name` de la ficha.
- 0.20.1: en la ficha de un remate en vivo (`body.lm-live-auction`) la plantilla de dos columnas pasa a una: título,
  datos en una fila, descripción y el remate a todo el ancho (video a la izquierda, puja a la derecha). Sin tocar Bricks.
- Dirección de Live: constante `LM_LIVE_URL` o filtro `lm_live_url` (por defecto `https://live.heavylisting.com`).

## 0.19.0: sincronización desde Heavy Listing Yard (14-nov-2026)
- `includes/class-lm-sync.php`: rutas firmadas `lucuma-mkt/v1/sync/{ping,equipment,dealer}` (HMAC-SHA256 con la opción `lm_yard_secret`) y `sync/secret` (administradores). Yard manda; la ficha se guarda con la lógica del panel del dealer.
- Las fichas existentes se reconocen sin duplicar; conservan su fecha. Borrar en Yard = borrador. Los dealers con `lm_yard_managed` ven el panel `/dealer/` en solo lectura.
- 0.19.1: el id de WordPress que manda Yard solo se usa si la ficha ya es de esa unidad (`lm_yard_id`) o, sin marca, si el stock coincide.
- 0.19.2: una foto que sale de la galería solo se borra si es de Yard, su padre es esa ficha y ninguna otra ficha la usa (destacada o `lm_gallery`). En el paso a producción, los modelos repetidos de Kurtz compartían un adjunto: la segunda ficha de cada par lo borró y dejó sin foto a la primera (71, 9, 79, 172). Se recuperaron reenviando desde Yard.

## 0.18.0: páginas marca × categoría (13-nov-2026)
- URL `/for-sale/<padre>/<categoría>/<marca>/` (p. ej. `/for-sale/construction/skid-steers/bobcat/`), paginación `/page/N/`. Sin regla de reescritura nueva: `LM_Combo::resolve()` (filtro `request`) separa el último tramo cuando es una marca y no una categoría.
- Indexable solo en categorías hoja y con 3 o más avisos no vendidos (`LM_Combo::MIN`). Las demás combinaciones funcionan, pero quedan `noindex, follow`. Canonical propio (filtro `seopress_titles_canonical`).
- Title "Used Bobcat Skid Steers for Sale", description con conteo y mediana, H1 "Bobcat Skid Steers for sale", migas Equipment / Construction / Skid Steers / Bobcat, BreadcrumbList e ItemList. No hay FAQ.
- La intro se genera con cifras en vivo (`LM_Landing::stats( $cat, $make )`) y enlaza la categoría y la marca. No repite el texto de la categoría.
- Enlaces: chips "Shop by make" bajo la intro de cada categoría hoja (solo las combinaciones indexables). En la barra lateral de una categoría, la lista de marcas muestra solo las de esa categoría, con su conteo ahí, y enlaza a la combinación.
- 0.18.1: sitemap propio `/make-category-sitemap.xml` con las combinaciones indexables (lastmod = aviso más reciente), sumado al índice `/sitemaps.xml` de SEOPress con el filtro `seopress_sitemaps_external_link`. Caché hasta que se guarde un aviso.

## 0.17.1: arreglo de indexación (13-nov-2026)
- En 0.16.0 `noindex_reason()` devolvía la descripción SEO personalizada como motivo, así que toda categoría con `lm_seo_desc` salía `noindex, follow` (las 6 landings, del 12 al 13-nov). Quitada esa línea: la descripción solo se usa en `desc_for_current()`.

## 0.17.0: perfil de dealer como sede local (13-nov-2026)
- Meta de usuario nueva: `lm_opening_hours` (texto libre tipo `Mon-Fri 7:30-17:00; Sat 8:00-12:00`, también acepta `Mo-Fr 07:30-17:00` y `8am-5pm`) y `lm_gbp` (enlace a la ficha de Google Business Profile). Editables en el panel (Company profile) y por REST `/wp/v2/users/{id}` (admins).
- `LM_Dealers::hours()` interpreta el horario; los tramos que no se entienden se ignoran (nunca llegan al schema). `LM_Dealers::map_url()`: GBP si existe, si no búsqueda en Google Maps con la dirección.
- Perfil `/dealers/<slug>/`: línea "Open Mon to Fri 7:30 am to 5:00 pm" y botón "Get directions" (evento `directions`).
- JSON-LD del perfil: `LocalBusiness` cuando hay calle y ciudad (si no, sigue `Organization`), con address, telephone, openingHours, hasMap, sameAs (web + GBP), image/logo y description con sus categorías.

## 0.16.0: landings de categoría (12-nov-2026)

El archivo de categoría es la landing (decisión en `marketplace-maquinaria/seo/estructura-urls.md`). Meta de término en `lm_category`, editable por REST (`/wp/v2/equipment-categories/<id>` → `meta`) y en wp-admin (editar categoría):
`lm_intro` (HTML bajo el H1), `lm_body` (HTML bajo la grilla), `lm_faq` (`[{q, a}]`), `lm_seo_title` (sin " | Heavy Listing"), `lm_seo_desc`.
Tokens en vivo: `{count}` `{median_price}` `{price_range}` `{dealers}` (inventario no vendido de la categoría y sus hijas).
Shortcodes: `[lm_archive_crumbs]` (categoría, marca y modelo), `[lm_category_intro]`, `[lm_category_content]` (cuerpo + FAQ). Intro y cuerpo solo en la vista limpia (página 1, sin filtros).
JSON-LD en archivos de categoría: BreadcrumbList siempre; ItemList y FAQPage en la vista limpia.

## Rematadores (0.21.0)
`includes/class-lm-auctioneers.php`. Búsquedas "<rematador> auction" / "auction schedule" (GSC 15-nov: Shetron, Hoover/Fraley).
- Taxonomía oculta `lm_auctioneer_org` sobre `lm_auction` (REST `/wp/v2/auctioneers`). Cada remate recibe su término
  solo, desde `LM_Auctions::auctioneer()` (`lm_auctioneer` o la empresa del dealer autor): al guardar en el admin, al
  crear/editar por REST (Yard, importador) y una vez por versión para todos (`lm_auctioneers_backfill`).
- `/auctioneers/` = página "auctioneers" (Bricks `rematadores`) con `[lm_auctioneers]`: índice ordenado por próximo remate.
  `/auctioneers/<slug>/` = la misma página con `lm_auctioneer_slug`: perfil (h1, datos, texto, próximos remates,
  términos para compradores, remates pasados, FAQ automática + `lm_faq`). Slug desconocido → 404.
- Rematador que es dealer (término con `lm_dealer` = id de usuario; se pone solo cuando el remate no trae `lm_auctioneer`):
  su URL es el perfil del dealer (301).
- Meta del término (admin por REST): `lm_website`, `lm_phone`, `lm_address`, `lm_city`, `lm_state`, `lm_zip`, `lm_about`
  (html), `lm_terms` (html), `lm_faq`, `lm_seo_title`, `lm_seo_desc`, `lm_dealer`.
- SEO: título "<Nombre> Auction Schedule: Upcoming Equipment Auctions", descripción con la próxima fecha, canonical
  propio, Organization + BreadcrumbList + FAQPage, `/auctioneer-sitemap.xml` en el índice de SEOPress. Indexable con
  2 remates o más, o con `lm_about`; si no, noindex, follow.
- En la ficha del remate el dato "Auctioneer" enlaza a su página; el título de `/auctions/` enlaza a `/auctioneers/`.
- `{price_range}` con un solo precio muestra "$28,500" (antes "$28,500 to $28,500").

## Directorio de dealers PA (0.22.0)

- CPT oculto `lm_dir_dealer` (REST `/wp/v2/directory-dealers`): dealers que NO están en Heavy Listing, con datos públicos de su propio sitio. Sin página propia.
- `[lm_dealer_directory]` en /dealers/ (debajo de `[lm_dealers]`): tarjetas por región (Statewide primero), enlaces nofollow, "Claim this listing" → /how-it-works/#dealers. Eventos GTM: directory_website, directory_inventory, directory_call, directory_claim.
- En cada categoría (`LM_Landing::category_content`): "<Categoría> dealers in Pennsylvania" = miembros con stock en esa categoría + dealers del directorio con ese tipo (lm_types, incluye subcategorías).
- JSON-LD ItemList en /dealers/.
- Carga: `seo/dealers/cargar.py [--aplicar]`. Si un dealer se vuelve cliente: `lm_user` = id de su usuario y su tarjeta deja de salir.
- Arreglos de rematadores: título sin "Auction Auction"; slug desconocido de /auctioneers/ ya no pinta el índice en el 404.
