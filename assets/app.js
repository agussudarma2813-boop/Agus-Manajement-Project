/* ProyekKita — interaksi ringan */
(function () {
  // Toggle sidebar di layar kecil
  document.addEventListener('click', function (e) {
    var burger = e.target.closest('[data-toggle-sidebar]');
    if (burger) {
      document.getElementById('sidebar').classList.toggle('is-open');
      return;
    }
    var side = document.getElementById('sidebar');
    if (side && side.classList.contains('is-open') && !e.target.closest('#sidebar')) {
      side.classList.remove('is-open');
    }
  });

  // Konfirmasi sebelum hapus
  document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (ev) {
      if (!window.confirm(f.getAttribute('data-confirm'))) ev.preventDefault();
    });
  });

  // Slider progress -> tampilkan angka + sarankan status
  document.querySelectorAll('input[type=range][data-progress]').forEach(function (r) {
    var out = document.getElementById(r.getAttribute('data-progress'));
    var sync = function () {
      if (out) out.textContent = r.value + '%';
      var preset = document.querySelector('select[name=status]');
      if (preset) {
        if (r.value === '100') preset.value = 'selesai';
        else if (r.value !== '0' && preset.value === 'belum') preset.value = 'proses';
      }
    };
    r.addEventListener('input', sync);
    sync();
  });

  // Filter pilihan lokasi mengikuti project yang dipilih
  var projectSel = document.querySelector('[data-project-select]');
  var lokSel = document.querySelector('[data-lokasi-select]');
  if (projectSel && lokSel) {
    var syncLokasi = function () {
      var pid = projectSel.value;
      var current = lokSel.value;
      var keep = current === '';
      Array.prototype.forEach.call(lokSel.options, function (o) {
        if (!o.dataset.project) return;
        var show = o.dataset.project === pid;
        o.hidden = !show;
        o.disabled = !show;
        if (show && o.value === current) keep = true;
      });
      if (!keep) lokSel.value = '';
    };
    projectSel.addEventListener('change', syncLokasi);
    syncLokasi();
  }
})();

/* Absensi massal: pilih semua, hari default, dan hitung yang dicentang */
(function () {
  var batch = document.querySelector('[data-batch-absensi]');
  if (!batch) return;

  var hariDefault = batch.querySelector('[data-hari-default]');
  var checkAll = batch.querySelector('[data-check-all]');
  var info = batch.querySelector('[data-absensi-info]');

  var rows = function () {
    return Array.prototype.slice.call(batch.querySelectorAll('[data-row-check]')).filter(function (c) { return !c.disabled; });
  };

  var sync = function () {
    var picked = rows().filter(function (c) { return c.checked; });
    if (info) {
      var hari = 0;
      picked.forEach(function (c) {
        var sel = batch.querySelector('select[name="hari[' + c.value + ']"]');
        hari += sel ? parseFloat(sel.value) : 1;
      });
      info.textContent = picked.length
        ? picked.length + ' pekerja dipilih · total ' + hari.toLocaleString('id-ID') + ' hari kerja akan dicatat.'
        : 'Pilih minimal satu pekerja.';
    }
    if (checkAll) {
      var all = rows();
      checkAll.checked = all.length > 0 && picked.length === all.length;
      checkAll.indeterminate = picked.length > 0 && picked.length < all.length;
    }
  };

  batch.addEventListener('change', function (e) {
    var t = e.target;

    if (t.matches('[data-check-all]')) {
      rows().forEach(function (c) {
        c.checked = t.checked;
        toggleRow(c, t.checked);
      });
      sync();
      return;
    }

    if (t.matches('[data-row-check]')) {
      toggleRow(t, t.checked);
      // terapkan jumlah hari default saat baru dicentang
      if (t.checked && hariDefault) {
        var sel = batch.querySelector('select[name="hari[' + t.value + ']"]');
        if (sel && sel.dataset.touched !== '1') sel.value = hariDefault.value;
      }
      sync();
      return;
    }

    if (t.matches('[data-hari-default]')) {
      rows().forEach(function (c) {
        var sel = batch.querySelector('select[name="hari[' + c.value + ']"]');
        if (sel) { sel.value = t.value; sel.dataset.touched = '1'; }
      });
      sync();
      return;
    }
  });

  batch.addEventListener('input', function (e) {
    if (e.target.matches('select[name^="hari["]')) {
      e.target.dataset.touched = '1';
      sync();
    }
  });

  function toggleRow(cb, on) {
    var tr = cb.closest('tr');
    if (tr) tr.classList.toggle('is-picked', !!on);
  }

  rows().forEach(function (c) { toggleRow(c, c.checked); });
  sync();
})();

/* Kalkulasi langsung upah borongan pada tabel penugasan */
(function () {
  var tables = document.querySelectorAll('[data-borongan-table]');
  if (!tables.length) return;

  var angka = function (s) {
    s = (s || '').trim();
    if (!s) return 0;
    s = s.replace(/[^0-9,.]/g, '');
    if (s.indexOf(',') >= 0) return parseFloat(s.replace(/\./g, '').replace(',', '.')) || 0;
    if (/^\d{1,3}(\.\d{3})+$/.test(s)) return parseFloat(s.replace(/\./g, '')) || 0;
    if ((s.match(/\./g) || []).length > 1) return parseFloat(s.replace(/\./g, '')) || 0;
    return parseFloat(s) || 0;
  };
  var rupiah = function (n) {
    return 'Rp ' + Math.round(n).toLocaleString('id-ID');
  };
  var num = function (n) {
    return n.toLocaleString('id-ID', { maximumFractionDigits: 1 });
  };

  tables.forEach(function (tbl) {
    var volKontrak = angka(tbl.dataset.volKontrak);
    var volReal = angka(tbl.dataset.volRealisasi);
    var upahDasar = angka(tbl.dataset.upahDasar);
    // volume efektif: volume akhir bila ada, kalau tidak pakai volume kontrak
    var vol = volReal > 0 ? volReal : volKontrak;

    var rows = Array.prototype.slice.call(tbl.querySelectorAll('[data-borongan-row]'));

    var hitung = function () {
      var perSatuan = [];
      rows.forEach(function (tr) {
        var check = tr.querySelector('[data-borongan-check]');
        var nominal = angka(tr.querySelector('[data-nominal]').value);
        if (check.checked && nominal <= 0) {
          perSatuan.push(tr);
        }
      });
      var pctTerpakai = 0, autoCount = 0;
      perSatuan.forEach(function (tr) {
        var pct = angka(tr.querySelector('[data-bagian]').value);
        if (pct > 0) pctTerpakai += pct; else autoCount++;
      });
      var sisa = Math.max(0, 100 - pctTerpakai);
      var total = 0;

      rows.forEach(function (tr) {
        var check = tr.querySelector('[data-borongan-check]');
        var out = tr.querySelector('[data-upah-hitung]');
        tr.classList.toggle('is-picked', check.checked);
        var tarifInput = tr.querySelector('[data-tarif]');
        var bagianInput = tr.querySelector('[data-bagian]');
        var nominalInput = tr.querySelector('[data-nominal]');
        [tarifInput, bagianInput, nominalInput].forEach(function (i) {
          i.disabled = !check.checked;
        });
        if (!check.checked) { out.textContent = '—'; return; }

        var nominal = angka(nominalInput.value);
        if (nominal > 0) {
          out.textContent = rupiah(nominal);
          total += nominal;
          return;
        }
        var tarif = angka(tarifInput.value) || upahDasar;
        var pct = angka(bagianInput.value);
        if (pct <= 0) pct = autoCount > 0 ? sisa / autoCount : 0;
        var nilai = tarif * vol * (pct / 100);
        out.textContent = rupiah(nilai);
        total += nilai;
      });

      var info = tbl.parentElement.querySelector('[data-borongan-total]');
      if (info) {
        info.innerHTML = 'Total upah borongan terhitung: <strong>' + rupiah(total) + '</strong>' +
          (volReal > 0 ? ' (volume akhir ' + num(volReal) + ')' : ' (dari volume kontrak ' + num(volKontrak) + ')') +
          ' · cair setelah pekerjaan berstatus Selesai.';
      }
    };

    tbl.addEventListener('input', hitung);
    tbl.addEventListener('change', hitung);

    // saat volume/upah dasar di form berubah, hitungan ikut menyesuaikan
    var form = tbl.closest('form');
    if (form) {
      var volInput = form.querySelector('[data-vol-realisasi]');
      var upahInput = form.querySelector('[data-harga-upah]');
      if (volInput) {
        volInput.addEventListener('input', function () {
          volReal = angka(volInput.value);
          vol = volReal > 0 ? volReal : volKontrak;
          tbl.dataset.volRealisasi = volInput.value;
          hitung();
        });
      }
      var volKontrakInput = form.querySelector('#volume');
      if (volKontrakInput) {
        volKontrakInput.addEventListener('input', function () {
          volKontrak = angka(volKontrakInput.value);
          vol = volReal > 0 ? volReal : volKontrak;
          hitung();
        });
      }
      if (upahInput) {
        upahInput.addEventListener('input', function () {
          upahDasar = angka(upahInput.value);
          hitung();
        });
      }
    }

    hitung();
  });

})();

/* Selisih harga jasa vs upah pada form harga satuan */
(function () {
  var angka = function (s) {
    s = (s || '').trim();
    if (!s) return 0;
    s = s.replace(/[^0-9,.]/g, '');
    if (s.indexOf(',') >= 0) return parseFloat(s.replace(/\./g, '').replace(',', '.')) || 0;
    if (/^\d{1,3}(\.\d{3})+$/.test(s)) return parseFloat(s.replace(/\./g, '')) || 0;
    if ((s.match(/\./g) || []).length > 1) return parseFloat(s.replace(/\./g, '')) || 0;
    return parseFloat(s) || 0;
  };
  var rupiah = function (n) { return 'Rp ' + Math.round(n).toLocaleString('id-ID'); };

  document.querySelectorAll('[data-harga-jasa]').forEach(function (jasa) {
    var form = jasa.closest('form');
    if (!form) return;
    var upah = form.querySelector('[data-harga-upah]');
    var out = form.querySelector('[data-harga-selisih]');
    if (!upah || !out) return;
    var sync = function () {
      var j = angka(jasa.value), u = angka(upah.value);
      if (!j && !u) {
        out.textContent = 'Isi harga jasa & upah untuk melihat selisih.';
        return;
      }
      var d = j - u;
      out.innerHTML = '<strong class="' + (d < 0 ? 'deadline-late' : '') + '">' + rupiah(d) + '</strong> per satuan' +
        ' &middot; jasa ' + rupiah(j) + ' − upah ' + rupiah(u) +
        ' &middot; untuk 10 satuan ' + rupiah(d * 10);
    };
    jasa.addEventListener('input', sync);
    upah.addEventListener('input', sync);
    sync();
  });
})();

/* Pilih item dari master harga satuan -> isi nama, kategori, satuan & harga */
(function () {
  var sel = document.querySelector('[data-harga-select]');
  if (!sel) return;

  var form = sel.closest('form') || document;
  var nama = form.querySelector('[data-nama-pekerjaan]');
  var kategori = form.querySelector('#kategori');
  var satuan = form.querySelector('input[name=satuan]');
  var jasa = form.querySelector('[data-harga-jasa]');
  var upah = form.querySelector('[data-harga-upah]');

  var isi = function (input, value) {
    if (!input) return;
    input.value = value || '';
    // beri tahu pendengar lain (hitungan borongan / selisih harga)
    input.dispatchEvent(new Event('input', { bubbles: true }));
  };

  sel.addEventListener('change', function () {
    var o = sel.options[sel.selectedIndex];
    if (!o || !o.value) return;
    isi(nama, o.dataset.nama || '');
    isi(kategori, o.dataset.kategori || '');
    isi(satuan, o.dataset.satuan || '');
    isi(jasa, o.dataset.jasa || '');
    isi(upah, o.dataset.upah || '');
  });
})();

/* Jadwal massal: pilih semua + info jumlah */
(function () {
  var form = document.querySelector('[data-jadwal-form]');
  if (!form) return;
  var checkAll = form.querySelector('[data-check-all]');
  var info = form.querySelector('[data-jadwal-info]');
  var boxes = function () {
    return Array.prototype.slice.call(form.querySelectorAll('[data-row-check]')).filter(function (c) { return !c.disabled; });
  };
  var sync = function () {
    var picked = boxes().filter(function (c) { return c.checked; });
    boxes().forEach(function (c) {
      var tr = c.closest('tr');
      if (tr) tr.classList.toggle('is-picked', c.checked);
    });
    if (info) {
      info.textContent = picked.length
        ? picked.length + ' tenaga akan dijadwalkan.'
        : 'Pilih minimal satu tenaga.';
    }
    if (checkAll) {
      var all = boxes();
      checkAll.checked = all.length > 0 && picked.length === all.length;
      checkAll.indeterminate = picked.length > 0 && picked.length < all.length;
    }
  };
  form.addEventListener('change', function (e) {
    if (e.target.matches('[data-check-all]')) {
      boxes().forEach(function (c) { c.checked = checkAll.checked; });
    }
    sync();
  });
  sync();
})();

/* Salin teks (rekap WA) */
(function () {
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-copy-target]');
    if (!btn) return;
    var el = document.querySelector(btn.getAttribute('data-copy-target'));
    if (!el) return;
    var teks = el.value || el.textContent || '';
    var done = function () {
      var asli = btn.textContent;
      btn.textContent = 'Tersalin ✓';
      setTimeout(function () { btn.textContent = asli; }, 1600);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(teks).then(done, function () {
        el.select(); document.execCommand('copy'); done();
      });
    } else {
      el.select(); document.execCommand('copy'); done();
    }
  });
})();

/* Input hasil kerja: banyak pekerjaan sekaligus + info tarif & upah per baris */
(function () {
  var form = document.querySelector('[data-laporan-form]');
  if (!form) return;

  var rowsBox = form.querySelector('[data-laporan-rows]');
  var info = form.querySelector('[data-laporan-info]');
  var totalOut = form.querySelector('[data-laporan-total]');
  var skema = form.dataset.skema;

  var rupiah = function (n) { return 'Rp ' + Math.round(n).toLocaleString('id-ID'); };
  var angka = function (s) {
    s = (s || '').trim();
    if (!s) return 0;
    s = s.replace(/[^0-9,.]/g, '');
    if (s.indexOf(',') >= 0) return parseFloat(s.replace(/\./g, '').replace(',', '.')) || 0;
    if (/^\d{1,3}(\.\d{3})+$/.test(s)) return parseFloat(s.replace(/\./g, '')) || 0;
    if ((s.match(/\./g) || []).length > 1) return parseFloat(s.replace(/\./g, '')) || 0;
    return parseFloat(s) || 0;
  };

  var baris = function () {
    return Array.prototype.slice.call(form.querySelectorAll('[data-laporan-row]'));
  };

  var syncBaris = function (tr) {
    var sel = tr.querySelector('[data-pekerjaan-select]');
    var o = sel ? sel.selectedOptions[0] : null;
    var tarif = o ? angka(o.dataset.tarif) : 0;
    var satuan = o ? (o.dataset.satuan || '') : '';
    var sisa = o ? angka(o.dataset.sisa) : 0;
    var kontrak = o ? angka(o.dataset.kontrak) : 0;
    var terlapor = o ? angka(o.dataset.terlapor) : 0;
    var dariMaster = o ? !!o.dataset.master : false;

    var satOut = tr.querySelector('[data-satuan-tampil]');
    if (satOut) satOut.value = satuan;

    var hint = tr.querySelector('[data-info-item]');
    if (hint) {
      hint.textContent = o && o.value
        ? (dariMaster
            ? 'Tarif ' + rupiah(tarif) + '/' + satuan + ' · item baru, otomatis ditambahkan ke project'
            : 'Tarif ' + rupiah(tarif) + '/' + satuan + ' · sisa ' + sisa.toLocaleString('id-ID')
              + ' dari kontrak ' + kontrak.toLocaleString('id-ID')
              + (terlapor > 0 ? ' · sudah dilaporkan ' + terlapor.toLocaleString('id-ID') : ''))
        : 'Pilih pekerjaan untuk melihat tarif & sisa volume.';
    }

    var vol = angka(tr.querySelector('[data-volume]') ? tr.querySelector('[data-volume]').value : 0);
    var out = tr.querySelector('[data-upah-hitung]');
    if (out) {
      if (skema === 'borongan') {
        out.innerHTML = vol > 0
          ? '<strong>' + rupiah(vol * tarif) + '</strong> = ' + vol.toLocaleString('id-ID') + ' ' + satuan + ' × ' + rupiah(tarif)
          : 'Isi volume untuk melihat upah.';
      } else {
        out.innerHTML = 'Upah harian — <strong>' + vol.toLocaleString('id-ID') + ' ' + satuan + '</strong> untuk tagihan.';
      }
      if (vol > 0 && !dariMaster && kontrak > 0 && vol > sisa) {
        out.innerHTML += '<br><span class="deadline-soon">Melebihi sisa kontrak (' + sisa.toLocaleString('id-ID') + ' ' + satuan + ')</span>';
      }
    }
  };

  var syncTotal = function () {
    var jml = 0, vol = 0, upah = 0;
    baris().forEach(function (tr) {
      var sel = tr.querySelector('[data-pekerjaan-select]');
      var o = sel ? sel.selectedOptions[0] : null;
      var v = angka(tr.querySelector('[data-volume]') ? tr.querySelector('[data-volume]').value : 0);
      if (!o || !o.value || v <= 0) return;
      jml++;
      vol += v;
      if (skema === 'borongan') upah += v * angka(o.dataset.tarif);
    });
    if (totalOut) {
      totalOut.innerHTML = jml
        ? '<strong>' + jml + ' pekerjaan</strong> · total volume ' + vol.toLocaleString('id-ID')
          + (skema === 'borongan' ? ' · upah ' + rupiah(upah) : '')
        : 'Belum ada volume diisi.';
    }
    if (info) {
      info.textContent = jml
        ? jml + ' pekerjaan siap disimpan.'
        : 'Isi satu baris saja kalau hari itu hanya mengerjakan satu pekerjaan.';
    }
  };

  var syncSemua = function () {
    baris().forEach(syncBaris);
    syncTotal();
  };

  form.addEventListener('input', syncSemua);
  form.addEventListener('change', function (e) {
    // ganti project -> muat ulang agar daftar pekerjaan mengikuti
    if (e.target.matches('[data-project-pilih]')) {
      var url = new URL(window.location.href);
      url.searchParams.set('project_id', e.target.value);
      url.searchParams.delete('pekerjaan_id');
      window.location.href = url.toString();
      return;
    }
    syncSemua();
  });

  form.addEventListener('click', function (e) {
    if (e.target.closest('[data-laporan-tambah]')) {
      var terakhir = baris()[baris().length - 1];
      if (!terakhir) return;
      var klon = terakhir.cloneNode(true);
      Array.prototype.forEach.call(klon.querySelectorAll('input[type=text]'), function (i) { i.value = ''; });
      Array.prototype.forEach.call(klon.querySelectorAll('select'), function (s) { s.selectedIndex = 0; });
      rowsBox.appendChild(klon);
      var sel = klon.querySelector('[data-pekerjaan-select]');
      if (sel) sel.focus();
      syncSemua();
      return;
    }
    var hapus = e.target.closest('[data-laporan-hapus]');
    if (hapus) {
      var row = hapus.closest('[data-laporan-row]');
      if (baris().length > 1) {
        row.remove();
      } else {
        Array.prototype.forEach.call(row.querySelectorAll('input[type=text]'), function (i) { i.value = ''; });
        Array.prototype.forEach.call(row.querySelectorAll('select'), function (s) { s.selectedIndex = 0; });
      }
      syncSemua();
    }
  });

  syncSemua();
})();

/* Unggah logo perusahaan (via server, token media tidak pernah ke browser) */
(function () {
  var input = document.querySelector('[data-logo-upload]');
  if (!input) return;
  var status = document.querySelector('[data-logo-status]');
  var field = document.querySelector('#logo');
  var csrf = document.querySelector('form input[name="_csrf"]');

  input.addEventListener('change', function () {
    if (!input.files || !input.files[0]) return;
    var fd = new FormData();
    fd.append('logo', input.files[0]);
    fd.append('_csrf', csrf ? csrf.value : '');
    if (status) status.textContent = 'Mengunggah…';
    fetch('logo_upload.php', { method: 'POST', body: fd })
      .then(function (r) { return r.json().catch(function () { return { ok: false, pesan: 'Balasan tidak dikenal' }; }); })
      .then(function (d) {
        if (d.ok) {
          if (field) field.value = d.url;
          if (status) status.innerHTML = 'Logo terunggah: <span class="mono">' + d.url + '</span>';
        } else if (status) {
          status.textContent = 'Gagal: ' + (d.pesan || 'tidak diketahui');
        }
      })
      .catch(function (e) { if (status) status.textContent = 'Gagal mengunggah: ' + e.message; });
  });
})();

/* Tombol salin teks bebas */
(function () {
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-copy-text]');
    if (!btn) return;
    var teks = btn.getAttribute('data-copy-text') || '';
    var done = function () {
      var asli = btn.textContent;
      btn.textContent = 'Tersalin ✓';
      setTimeout(function () { btn.textContent = asli; }, 1500);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(teks).then(done, done);
    } else {
      done();
    }
  });
})();
