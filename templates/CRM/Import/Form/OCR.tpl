{*
 * OCR Import Extension for CiviCRM - Circle Interactive 2013
 * Author: andyw@circle
 *
 * Distributed under the GNU Affero General Public License, version 3
 * http://www.gnu.org/licenses/agpl-3.0.html 
 *}

<h3>{ts}Upload OCR File{/ts}</h3>
  <table class="form-layout">
    <tr>
        <td class="label">{$form.uploadFile.label}</td>
        <td>{$form.uploadFile.html}<br />
            <div class="description">{ts}File format must be OCR.{/ts}</div>
            {ts 1=$uploadSize}Maximum Upload File Size: %1 MB{/ts}
        </td>
    </tr>
{*    
    <tr>

        <td></td>
        <td>{$form.skipColumnHeader.html} {$form.skipColumnHeader.label}
            <div class="description">{ts}Check this box if the first row of your file consists of field names (Example: 'First Name','Last Name','Email'){/ts}</div>
        </td>
    </tr>
*}
  </table>

