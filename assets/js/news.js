import '@/js/base'
import '@/js/_datatables'

import $ from 'jquery'
import language from 'datatables.net-plugins/i18n/German.lang'

$(document).ready(() => {
  const dataTable = $('#news')
  const newsItemUrlTemplate = dataTable.data('newsItemUrlTemplate')
  dataTable.DataTable({
    language: language,
    lengthMenu: [25, 50, 100],
    pageLength: 25,
    processing: false,
    serverSide: true,
    order: [[0, 'desc']],
    searchDelay: 100,
    pagingType: 'numbers',
    ajax: {
      cache: true,
      url: dataTable.data('ajaxUrl')
    },
    columns: [
      {
        data: 'lastModified',
        orderable: true,
        searchable: false,
        className: 'd-none d-md-table-cell',
        render: (data, type, row) => {
          if (data) {
            const date = new Date(data)
            if (type === 'display') {
              return date.toLocaleDateString('de-DE')
            }
            return date.getTime()
          }
          return data
        }
      },
      {
        data: 'title',
        orderable: false,
        searchable: true,
        render: (data, type, row) => {
          if (type === 'display' && data) {
            const newsItemUrl = newsItemUrlTemplate
              .replace('1-slug', encodeURI(row.slug))
            return `<a href="${newsItemUrl}">${data}</a>`
          }
          return data
        }
      },
      {
        data: 'author.name',
        orderable: false,
        searchable: true,
        className: 'd-none d-xl-table-cell',
        render: (data, type, row) => {
          if (type === 'display' && data) {
            return `<a href="${row.author.uri}">${data}</a>`
          }
          return data
        }
      }
    ]
  })
})
