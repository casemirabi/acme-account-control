function softReload() {
  try {
    // mantém a tela “no mesmo lugar”
    const y = window.scrollY || 0;
    sessionStorage.setItem('acme_scroll_y', String(y));
  } catch (e) { }
  window.location.reload();
}

document.addEventListener('DOMContentLoaded', () => {
  try {
    const y = Number(sessionStorage.getItem('acme_scroll_y') || '0');
    if (y > 0) window.scrollTo(0, y);
    sessionStorage.removeItem('acme_scroll_y');
  } catch (e) { }
});

function storageKey() {
  return 'acme_clt_last_result_v1';
}

function saveLastResult(payload) {
  try {
    sessionStorage.setItem(storageKey(), JSON.stringify(payload));
  } catch (e) { }
}
function loadLastResult() {
  try {
    const raw = sessionStorage.getItem(storageKey());
    if (!raw) return null;
    return JSON.parse(raw);
  } catch (e) {
    return null;
  }
}
function clearLastResult() {
  try {
    sessionStorage.removeItem(storageKey());
  } catch (e) { }
}

(function () {
  function onlyDigits(s) {
    return String(s || '').replace(/\D+/g, '');
  }
  function el(id) {
    return document.getElementById(id);
  }
  function sleep(ms) {
    return new Promise((r) => setTimeout(r, ms));
  }

  function pickErrorMessage(v) {
    //console.log("pickErrorMessage input:", v, typeof v);
    if (!v) return '';

    if (typeof v === 'string') return v;

    if (typeof v === 'object') {
      if (typeof v.message === 'string' && v.message.trim()) return v.message;
      if (typeof v.error === 'string' && v.error.trim()) return v.error;
      if (v.error && typeof v.error === 'object') {
        if (typeof v.error.message === 'string' && v.error.message.trim()) {
          return v.error.message;
        }
        if (typeof v.error.code === 'string' && v.error.code.trim()) {
          return v.error.code;
        }
      }
      if (typeof v.code === 'string' && v.code.trim()) return v.code;
    }

    try {
      return JSON.stringify(v);
    } catch (e) {
      return String(v);
    }
  }



  function renderMsg(box, html, kind) {
    var cls =
      'acme-msg' +
      (kind === 'err'
        ? ' acme-msg-err'
        : kind === 'ok'
          ? ' acme-msg-ok'
          : '');
    box.innerHTML = '<div class="' + cls + '">' + html + '</div>';
  }

  // ============================================================
  // "Reticências mexendo" (loading dots) - usado no "Iniciando"
  // ============================================================
  let acmeDotsTimer = null;

  function startMovingDots(box, baseText, kind) {
    stopMovingDots(); // não acumular timers

    let step = 0;
    acmeDotsTimer = setInterval(() => {
      step = (step + 1) % 4; // 0..3
      const dots = '.'.repeat(step);
      renderMsg(box, baseText + dots, kind);
    }, 350);
  }

  function stopMovingDots() {
    if (acmeDotsTimer) {
      clearInterval(acmeDotsTimer);
      acmeDotsTimer = null;
    }
  }

  // ============================================================
  // Contador regressivo do "tempo estimado" - usado no "Processando"
  // ============================================================
  let acmeCountdownTimer = null;

  function formatSecondsAsMMSS(totalSeconds) {
    const s = Math.max(0, Number(totalSeconds) || 0);
    const mm = String(Math.floor(s / 60)).padStart(2, '0');
    const ss = String(Math.floor(s % 60)).padStart(2, '0');
    return `${mm}:${ss}`;
  }

  function startCountdownProcessing(box, totalSeconds) {
    stopCountdownProcessing();

    const startedAt = Date.now();
    const totalMs = Math.max(0, Number(totalSeconds) || 0) * 1000;

    const tick = () => {
      const elapsedMs = Date.now() - startedAt;
      const remainingMs = Math.max(0, totalMs - elapsedMs);
      const remainingSeconds = Math.ceil(remainingMs / 1000);

      renderMsg(
        box,
        `Consulta iniciada ✅<br><b>Processando… (tempo estimado: ${formatSecondsAsMMSS(
          remainingSeconds
        )})</b>`,
        ''
      );

      if (remainingMs <= 0) {
        // chegou no 00:00, não precisa ficar rodando
        stopCountdownProcessing();
      }
    };

    tick(); // renderiza imediatamente
    acmeCountdownTimer = setInterval(tick, 1000);
  }

  function stopCountdownProcessing() {
    if (acmeCountdownTimer) {
      clearInterval(acmeCountdownTimer);
      acmeCountdownTimer = null;
    }
  }

  function fmtMoney(v) {
    const n = Number(v || 0);
    return n.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
  }

  /*function fmtDate(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    if (isNaN(d.getTime())) return '—';
    return d.toLocaleDateString('pt-BR');
  }*/
  function fmtDate(iso) {
    if (!iso) return '—';

    const value = String(iso).trim();
    const match = value.match(/^(\d{4})-(\d{2})-(\d{2})$/);

    if (match) {
      return `${match[3]}/${match[2]}/${match[1]}`;
    }

    const d = new Date(value);
    if (isNaN(d.getTime())) return '—';
    return d.toLocaleDateString('pt-BR');
  }

  // cpf do banco vem mascarado "000****07" OU pode vir completo.
  // aqui só garantimos uma máscara "bonitinha" quando vier 11 dígitos.
  function maskCpfAny(cpf) {
    cpf = String(cpf || '').trim();
    const digits = cpf.replace(/\D+/g, '');
    if (digits.length === 11) {
      return (
        digits.slice(0, 3) +
        '.' +
        digits.slice(3, 6) +
        '.' +
        digits.slice(6, 9) +
        '-' +
        digits.slice(9, 11)
      );
    }
    // se já veio mascarado, só retorna como veio
    return cpf || '***.***.***-**';
  }

  // nome do root (agora sempre vem do server)
  // - se vier vazio por algum motivo, cai em "Dados restringidos"
  function showNome(v) {
    v = v === null || v === undefined ? '' : String(v).trim();
    return v ? v : 'Dados restringidos';
  }

  function sexoLabel(v) {
    v = String(v || '').toUpperCase();
    if (v === 'F') return 'Feminino';
    if (v === 'M') return 'Masculino';
    return '—';
  }

  // ============================================================
  // Normalização do retorno (compatível com formatos antigos + novo do BANCO)
  // ============================================================
  function normalizeDados(dadosRaw) {
    // 1) formato do banco: array com 1 objeto
    if (Array.isArray(dadosRaw)) {
      // se for array de objetos do novo formato
      if (
        dadosRaw.length > 0 &&
        dadosRaw[0] &&
        typeof dadosRaw[0] === 'object' &&
        (dadosRaw[0].margem || dadosRaw[0].vinculos || dadosRaw[0].cpf)
      ) {
        const root = dadosRaw[0];
        const vinc = Array.isArray(root.vinculos) ? root.vinculos : [];
        return { root: root, vinculos: vinc };
      }

      // se for legado: array de vínculos
      return { root: null, vinculos: dadosRaw };
    }

    // 2) objeto direto (novo)
    if (dadosRaw && typeof dadosRaw === 'object') {
      const root = dadosRaw;
      const vinc = Array.isArray(root.vinculos) ? root.vinculos : [];
      return { root: root, vinculos: vinc };
    }

    return { root: null, vinculos: [] };
  }

  // elegível: qualquer vinculo.elegivel == true (compatível com "Elegivel" legado)
  function hasEligible(vinculos) {
    if (!Array.isArray(vinculos) || vinculos.length === 0) return false;
    return vinculos.some((v) => {
      if (!v || typeof v !== 'object') return false;
      if (Object.prototype.hasOwnProperty.call(v, 'elegivel'))
        return v.elegivel === true || v.elegivel === 1 || v.elegivel === 'true';
      if (Object.prototype.hasOwnProperty.call(v, 'Elegivel'))
        return v.Elegivel === true || v.Elegivel === 1 || v.Elegivel === 'true';
      return false;
    });
  }

  // detecta se há simulações (capturedResponse.body) e retorna array parseado
  /*function extractSimulacoes(root) {
    try {
      const body =
        root &&
        root.propostas &&
        root.propostas.capturedResponse &&
        root.propostas.capturedResponse.body;
      if (!body) return null;
      const arr = JSON.parse(body);
      return Array.isArray(arr) ? arr : null;
    } catch (e) {
      return null;
    }
  }*/
  function extractSimulacoes(root) {
    try {
      if (!root || typeof root !== 'object') return null;

      if (Array.isArray(root.propostas)) {
        return root.propostas;
      }

      const body =
        root &&
        root.propostas &&
        root.propostas.capturedResponse &&
        root.propostas.capturedResponse.body;

      if (!body) return null;

      const arr = JSON.parse(body);
      return Array.isArray(arr) ? arr : null;
    } catch (e) {
      return null;
    }
  }

  function makePdfLink(requestId) {
    // ACME_CLT vem do wp_localize_script (PHP)
    return ACME_CLT.pdfUrl + '&request_id=' + encodeURIComponent(requestId);
  }

  // ============================================================
  // Card final (somente completed)
  // ============================================================
  function renderResultCard(container, dadosRaw, requestId) {
    const norm = normalizeDados(dadosRaw);
    const root = norm.root;
    const vinculos = norm.vinculos;

    // Se não tem root no novo formato e também não tem vínculos (legado vazio), devolve msg
    if (!root && (!Array.isArray(vinculos) || vinculos.length === 0)) {
      container.innerHTML =
        '<div class="acme-msg acme-msg-err">Nenhum dado retornado.</div>';
      return;
    }

    // Campos do front (vêm do root.margem)
    const margem =
      root && root.margem && typeof root.margem === 'object' ? root.margem : {};

    const nome = showNome(root && root.nome ? root.nome : '');
    const cpf = root && (root.cpf || root.numeroDocumento)
      ? String(root.cpf || root.numeroDocumento)
      : '—';
    const valorMargemDisponivel = fmtMoney(margem.valorMargemDisponivel);
    const valorMargemBase = fmtMoney(margem.valorMargemBase);
    const dataNascimento = fmtDate(margem && margem.dataNascimento ? margem.dataNascimento : '');
    const sexo = sexoLabel(margem && margem.sexo ? margem.sexo : '');
    const dataAdmissao = fmtDate(margem && margem.dataAdmissao ? margem.dataAdmissao : '');

    const elegivel = hasEligible(vinculos);
    const badgeClass = elegivel
      ? 'acme-badge acme-badge-ok'
      : 'acme-badge acme-badge-bad';
    const badgeText = elegivel ? 'Elegível' : 'Não elegível';

    const pdfHref = requestId ? makePdfLink(requestId) : '#';

    // Simulações (se existir capturedResponse.body)
    const simulacoes = extractSimulacoes(root);
    const hasSim = Array.isArray(simulacoes) && simulacoes.length > 0;

    // Detalhes: ou tabela de vínculos, ou tabela de simulações
    let detailsTitle = '';
    let detailsBody = '';

    if (hasSim) {
      detailsTitle = `Simulações (${simulacoes.length})`;

      const simRows = simulacoes
        .map((s) => {
          const nomeSim = s && s.nome ? String(s.nome) : '—';
          const prazo =
            s && s.prazo !== undefined && s.prazo !== null
              ? String(s.prazo)
              : '—';
          const taxa =
            s && s.taxaJuros !== undefined && s.taxaJuros !== null
              ? String(s.taxaJuros) + '%'
              : '—';
          const valorLib =
            s && s.valorLiberado !== undefined && s.valorLiberado !== null
              ? fmtMoney(s.valorLiberado)
              : '—';
          const parcela =
            s && s.valorParcela !== undefined && s.valorParcela !== null
              ? fmtMoney(s.valorParcela)
              : '—';

          return `<tr>
          <td>${nomeSim}</td>
          <td>${prazo}</td>
          <td>${taxa}</td>
          <td>${valorLib}</td>
          <td>${parcela}</td>
        </tr>`;
        })
        .join('');

      detailsBody = `
        <div style="overflow:auto; margin-top:8px;">
          <table class="acme-tbl">
            <thead>
              <tr>
                <th>Nome</th>
                <th>Prazo</th>
                <th>Taxa</th>
                <th>Valor liberado</th>
                <th>Parcela</th>
              </tr>
            </thead>
            <tbody>${simRows}</tbody>
          </table>
        </div>
      `;
    } else {
      detailsTitle = `Ver vínculos retornados (${Array.isArray(vinculos) ? vinculos.length : 0
        })`;

      const rows = (Array.isArray(vinculos) ? vinculos : [])
        .map((v) => {
          const elg =
            v &&
              (v.elegivel === true ||
                v.Elegivel === true ||
                v.elegivel === 1 ||
                v.Elegivel === 1 ||
                v.elegivel === 'true' ||
                v.Elegivel === 'true')
              ? 'Sim'
              : 'Não';
          const reg =
            v && (v.numeroRegistro || v.NumeroRegistro)
              ? String(v.numeroRegistro || v.NumeroRegistro)
              : '—';
          const doc =
            v && (v.numeroDocumento || v.NumeroDocumento)
              ? String(v.numeroDocumento || v.NumeroDocumento)
              : '—';
          const cnpj =
            v && (v.numeroDocumentoEmpregador || v.NumeroDocumentoEmpregador)
              ? String(
                v.numeroDocumentoEmpregador || v.NumeroDocumentoEmpregador
              )
              : '—';

          return `<tr>
          <td>${elg}</td>
          <td>${reg}</td>
          <td>${doc}</td>
          <td>${cnpj}</td>
        </tr>`;
        })
        .join('');

      detailsBody = `
        <div style="overflow:auto; margin-top:8px;">
          <table class="acme-tbl">
            <thead>
              <tr>
                <th>Elegível</th>
                <th>Registro</th>
                <th>Documento</th>
                <th>CNPJ Empregador</th>
              </tr>
            </thead>
            <tbody>${rows}</tbody>
          </table>
        </div>
      `;
    }

    container.innerHTML = `
      <div class="acme-card">
        <div class="acme-card-h">
          <div class="acme-title">Resultado da Consulta CLT</div>
          <div class="acme-actions">
            <a class="acme-btn" href="${pdfHref}" target="_blank" rel="noopener">Baixar PDF</a>
            <div class="${badgeClass}">${badgeText}</div>
          </div>
        </div>

        <div class="acme-card-b">
          <div class="acme-grid">
            <div class="acme-field">
              <div class="acme-label">Nome</div>
              <div class="acme-value">${nome}</div>
            </div>

          <div class="acme-field">
            <div class="acme-label">CPF</div>
            <div class="acme-value">${cpf}</div>
          </div>

            <div class="acme-field">
              <div class="acme-label">Margem disponível</div>
              <div class="acme-value">${valorMargemDisponivel}</div>
            </div>

            <div class="acme-field">
              <div class="acme-label">Margem base</div>
              <div class="acme-value">${valorMargemBase}</div>
            </div>

            <div class="acme-field">
              <div class="acme-label">Nascimento</div>
              <div class="acme-value">${dataNascimento}</div>
            </div>

            <div class="acme-field">
              <div class="acme-label">Sexo</div>
              <div class="acme-value">${sexo}</div>
            </div>

            <div class="acme-field">
              <div class="acme-label">Admissão</div>
              <div class="acme-value">${dataAdmissao}</div>
            </div>

            <div class="acme-field">
              <div class="acme-label">Status</div>
              <div class="acme-value">Completo</div>
            </div>
          </div>

          <details style="margin-top:12px;">
            <summary class="acme-summary">${detailsTitle}</summary>
            ${detailsBody}
          </details>

        </div>
      </div>
    `;
  }

  if (!window.ACME_CLT_UI || typeof window.ACME_CLT_UI !== 'object') {
    window.ACME_CLT_UI = {};
  }
  window.ACME_CLT_UI.renderResultCard = renderResultCard;

  async function fetchConsultaStatusByRequestId(requestId) {
    const url = new URL(ACME_CLT.restStatus);
    url.searchParams.set('request_id', String(requestId || ''));

    const resp = await fetch(url.toString(), {
      method: 'GET',
      credentials: 'same-origin',
      headers: {
        'X-WP-Nonce': ACME_CLT.restNonce,
      },
    });

    const text = await resp.text();
    let json = null;

    try {
      json = JSON.parse(text);
    } catch (e) { }

    if (!resp.ok || !json || json.success !== true) {
      throw new Error(
        (json && (json.message || (json.error && json.error.message))) ||
        'Não foi possível carregar a consulta.'
      );
    }

    return json.data || {};
  }

  function initCltPanelViewer() {
    const modal = document.getElementById('acme-clt-panel-modal');
    if (!modal) return;

    const resultBox = modal.querySelector('.acme-clt-panel-modal-result');
    if (!resultBox) return;

    const closeModal = () => {
      modal.setAttribute('hidden', 'hidden');
      document.documentElement.classList.remove('acme-clt-modal-open');
      resultBox.innerHTML = '';
    };

    const openModal = () => {
      modal.removeAttribute('hidden');
      document.documentElement.classList.add('acme-clt-modal-open');
    };

    document.addEventListener('click', async (event) => {
      const closeTrigger = event.target.closest('[data-acme-clt-close="1"]');
      if (closeTrigger) {
        closeModal();
        return;
      }

      const viewBtn = event.target.closest('.acme-clt-view-btn');
      if (!viewBtn) return;

      event.preventDefault();

      const requestId = String(viewBtn.getAttribute('data-request-id') || '').trim();
      if (!requestId) return;

      openModal();
      resultBox.innerHTML = '<div class="acme-msg">Carregando consulta...</div>';

      try {
        const data = await fetchConsultaStatusByRequestId(requestId);

        if (data.status === 'failed') {
          const err = pickErrorMessage(data.error);
          resultBox.innerHTML = '<div class="acme-msg acme-msg-err">' + err + '</div>';
          return;
        }

        if (data.status !== 'completed') {
          resultBox.innerHTML = '<div class="acme-msg">Consulta ainda não foi concluída.</div>';
          return;
        }

        const dadosRaw = extractDadosFromStatus({ data });

        if (!dadosRaw) {
          resultBox.innerHTML = '<div class="acme-msg acme-msg-err">Consulta concluída, mas sem dados para exibir.</div>';
          return;
        }

        renderResultCard(resultBox, dadosRaw, requestId);

      } catch (error) {
        resultBox.innerHTML = '<div class="acme-msg acme-msg-err">' + pickErrorMessage(error) + '</div>';
      }
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && !modal.hasAttribute('hidden')) {
        closeModal();
      }
    });
  }

  // ============================================================
  // REST calls
  // ============================================================
  async function startConsulta(cpf) {
    const resp = await fetch(ACME_CLT.restStart, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': ACME_CLT.restNonce,
      },
      body: JSON.stringify({
        cpf: String(cpf || ''),
        wait: true,
        wait_timeout: 25,
      }),
    });

    const text = await resp.text();
    let json = null;
    try {
      json = JSON.parse(text);
    } catch { }

    //console.log("START raw text:", text);
    //console.log("START parsed json:", json);
    //console.log("START typeof error:", typeof json?.error);


    if (!resp.ok) {
      console.error('API-CLT HTTP error body:', text);
      const msg =
        json && json.error && json.error.message
          ? json.error.message
          : json && json.error
            ? json.error
            : json && json.message
              ? json.message
              : text || `HTTP ${resp.status}`;
      throw new Error(msg);
    }

    return json;
  }

  async function getStatus(requestId) {
    const url =
      ACME_CLT.restStatus + '?request_id=' + encodeURIComponent(requestId);
    //const resp = await fetch(url, { method: 'GET', credentials: 'same-origin' });
    const resp = await fetch(url, {
      method: 'GET',
      credentials: 'same-origin',
      headers: {
        'X-WP-Nonce': ACME_CLT.restNonce
      }
    });

    const json = await resp.json().catch(() => null);

    //console.log("STATUS response:", json);
    //console.log("STATUS typeof error:", typeof json?.error);
    //console.log("STATUS data:", json?.data);


    /*if (!resp.ok) {
      const msg =
        json && (json.error || json.message)
          ? json.error || json.message
          : `Erro (${resp.status})`;
      throw new Error(msg);
    }*/
    if (!json || json.success !== true)
      throw new Error('Erro ao consultar status. Revise os dados e tente novamente!');
    return json;
  }

  // status pode vir em st.status, st.data.status, etc.
  function extractStatus(st) {
    if (!st) return '';
    if (st.status) return String(st.status);
    if (st.data && st.data.status) return String(st.data.status);
    if (st.data && st.data.request && st.data.request.status)
      return String(st.data.request.status);
    if (st.row && st.row.status) return String(st.row.status);
    return '';
  }

  // extrai "dados" do retorno do START
  function extractDadosFromStart(started) {
    if (!started) return null;

    if (started.response_data && started.response_data.dados !== undefined)
      return started.response_data.dados;
    if (
      started.data &&
      started.data.response_data &&
      started.data.response_data.dados !== undefined
    )
      return started.data.response_data.dados;

    if (started.dados !== undefined) return started.dados;

    if (started.response_data !== undefined) return started.response_data;

    return null;
  }

  // extrai "dados" do retorno do STATUS
  function extractDadosFromStatus(st) {
    if (!st) return null;

    if (st.data && st.data.response_data && st.data.response_data.dados !== undefined)
      return st.data.response_data.dados;
    if (st.data && st.data.dados !== undefined) return st.data.dados;

    if (st.response_data && st.response_data.dados !== undefined)
      return st.response_data.dados;
    if (st.dados !== undefined) return st.dados;

    return null;
  }

  // ============================================================
  // Runner: espera até 1:30 por completed/failed
  // ============================================================
  async function run() {
    var input = el('acme-cpf');
    var box = el('acme-clt-result');
    var btn = el('acme-btn');

    if (!input || !box || !btn) return;

    var cpf = onlyDigits(input.value);
    if (cpf.length !== 11) {
      renderMsg(box, 'CPF inválido. Digite 11 números.', 'err');
      return;
    }

    btn.disabled = true;
    startMovingDots(box, 'Iniciando consulta', '');

    try {
      const started = await startConsulta(cpf);
      stopMovingDots();

      const requestId = started.request_id;

      // Se o start já finalizou
      if (started.status === 'completed') {
        const dadosRaw = extractDadosFromStart(started);

        if (dadosRaw) {
          renderResultCard(box, dadosRaw, requestId);
          saveLastResult({ requestId, status: 'completed', dados: dadosRaw });
        } else {
          const msg =
            started.error && started.error.message
              ? started.error.message
              : started.message
                ? started.message
                : 'Consulta finalizada, porém sem dados para exibir.';
          renderMsg(box, msg, 'err');
          saveLastResult({ requestId, status: 'completed', error: msg });
        }

        softReload();
        return;
      }

      if (started.status === 'failed') {
        const err =
          started.error && started.error.message
            ? started.error.message
            : 'Falha na consulta.';
        renderMsg(box, 'Falhou: ' + err, 'err');
        saveLastResult({ requestId, status: 'failed', error: err });
        softReload();
        return;
      }

      // Pending/processing: polling + contador regressivo do estimado
      startCountdownProcessing(box, 180); // 03:00

      const t0 = Date.now();
      const timeoutMs = 180000; // 3:00 (180 segundos em ms)
      const intervalMs = 3000; // checa a cada 3s

      while (true) {
        await sleep(intervalMs);

        const st = await getStatus(requestId);

        //console.log("Polling status response:", st);

        const status = extractStatus(st);

        // se ainda não veio status, considera pendente
        if (!status || status === 'pending' || status === 'processing') {
          if (Date.now() - t0 > timeoutMs) {
            stopCountdownProcessing();
            renderMsg(
              box,
              'Ainda processando…<br>O tempo estimado de 1 minuto e 30 segundos foi excedido.<br><a href="/consultas-clt">Acompanhar status da consulta</a>',
              ''
            );
            return;
          }
          continue;
        }

        if (status === 'failed') {
          stopCountdownProcessing();

          //console.log("FAILED status object:", st);
          //console.log("FAILED error field:", st?.data?.error || st?.error);

          const err =
            st.data && st.data.error && st.data.error.message
              ? st.data.error.message
              : st.error && st.error.message
                ? st.error.message
                : 'Falha na consulta.';
          /*renderMsg(box, 'Falhou: ' + err, 'err');*/
          saveLastResult({ requestId, status: 'failed', error: err });

          softReload();
          return;
        }

        if (status === 'completed') {
          stopCountdownProcessing();
          const dadosRaw = extractDadosFromStatus(st);

          if (dadosRaw) {
            renderResultCard(box, dadosRaw, requestId);
            saveLastResult({ requestId, status: 'completed', dados: dadosRaw });
          } else {
            const msg =
              st.data && st.data.error && st.data.error.message
                ? st.data.error.message
                : st.error && st.error.message
                  ? st.error.message
                  : st.message
                    ? st.message
                    : 'Consulta finalizada, porém sem dados para exibir.';
            renderMsg(box, msg, 'err');
            saveLastResult({ requestId, status: 'completed', error: msg });
          }

          softReload();
          return;
        }

        // qualquer outro status explícito do backend
        stopCountdownProcessing();
        renderMsg(box, 'Status inesperado: ' + status, 'err');
        return;
      }
    } catch (e) {
      stopMovingDots();
      stopCountdownProcessing();
      renderMsg(
        box,
        e && e.message ? e.message : 'Falha na requisição.',
        'err'
      );
    } finally {
      stopMovingDots();
      stopCountdownProcessing();
      btn.disabled = false;
    }
  }

  // Render de “último resultado” caso tenha sido salvo (ex.: refresh)
  document.addEventListener('DOMContentLoaded', function () {
    const box = el('acme-clt-result');
    if (!box) return;

    const saved = loadLastResult();

    if (saved && saved.status === 'completed') {
      if (saved.dados) {
        renderResultCard(box, saved.dados, saved.requestId);
      } else {
        renderMsg(
          box,
          saved.error || 'Consulta finalizada, porém sem dados para exibir.',
          'err'
        );
      }
      clearLastResult();
    } else if (saved && saved.status === 'failed') {
      renderMsg(box, saved.error || 'Falha na consulta.', 'err');
      clearLastResult();
    }
  });

  document.addEventListener('click', function (e) {
    if (e.target && e.target.id === 'acme-btn') {
      e.preventDefault();
      run();
    }
  });

  initCltPanelViewer();
})();