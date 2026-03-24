# Deploy na Netlify (com Functions)

## 1) Método de deploy recomendado
Use **Import from Git** (GitHub/GitLab/Bitbucket) ou Netlify CLI.

Deploy por “arrastar pasta pronta” normalmente é para estático e pode não compilar Functions/dependências.

## 2) Variáveis de ambiente
No painel da Netlify:
`Site configuration -> Environment variables`

Adicione:

- `DUTTYFY_API_KEY`
- `DUTTYFY_PIX_URL_ENCRYPTED`
- `DUTTYFY_WEBHOOK_URL`
- `DUTTYFY_DEFAULT_CUSTOMER_NAME`
- `DUTTYFY_DEFAULT_CUSTOMER_DOCUMENT`
- `DUTTYFY_DEFAULT_CUSTOMER_EMAIL`
- `DUTTYFY_DEFAULT_CUSTOMER_PHONE`
- `META_PIXEL_ID`
- `META_CONVERSIONS_API_ACCESS_TOKEN`
- `META_GRAPH_API_VERSION`
- `META_TEST_EVENT_CODE`

Use `.env.example` como referência.

## 3) Rotas da API
As rotas antigas continuam iguais no front:

- `/api/create-pix`
- `/api/check-pix`
- `/api/meta-event`
- `/api/webhooks/duttyfy`

Elas são redirecionadas para Netlify Functions pelo `netlify.toml`.

Arquivos PHP do diretório `api/` e `storage/` ficam bloqueados no deploy da Netlify (não ficam públicos).

## 4) Webhook DuttyFy
No painel da DuttyFy, configure o webhook com a URL final da Netlify:

`https://SEU-SITE.netlify.app/api/webhooks/duttyfy`
