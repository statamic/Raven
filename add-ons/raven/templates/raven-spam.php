<div id="subnav">
  <ul>
  <li><a href="<?php echo $app->urlFor("raven") ?>"><?php echo Localization::fetch('Overview')?></a></li>
  <li class="separator">&nbsp;</li>
    <?php foreach ($formsets as $name => $values): ?>
      <li><a href="<?php echo $app->urlFor("raven") . '/' . $name ?>" <?php if ($formset['name'] === $name): ?> class="active"<?php endif ?> ><?php echo Slug::prettify($name) ?></a></li>
    <?php endforeach ?>
  </ul>
</div>

<div class="container">

  <div id="status-bar" class="web">
    <div class="status-block">
      <strong><?php echo Slug::prettify($formset['name']) ?></strong> <span class="muted"><?php echo Localization::fetch('form', null, true) ?> <?php echo Localization::fetch('spam', null, true)?>
    </div>
    <ul>
      <li>
        <a href="<?php echo $app->urlFor("raven") . '/' . $formset['name'] ?>">
          <?php echo Localization::fetch('all_submissions')?>
        </a>
      </li>
    </ul>
  </div>

  <form action="<?php echo $app->urlFor('raven') . '/' . $formset['name'] . '/batch' ?>" method="POST">
    <div class="section">
      <table class="simple-table sortable">
        <thead>
          <tr>
            <th class="checkbox-col"></th>
            <?php foreach ($fields as $field => $name): ?>
              <th><?php echo $name ?></th>
            <?php endforeach ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($spam as $file): ?>
            <tr>
              <td class="checkbox-col">
                <input type="checkbox" name="files[]" value="<?php echo array_get($file, 'meta:path') ?>" data-bind="checked: selectedFiles" >
              </td>
              <?php foreach ($fields as $field => $name): ?>
                <td><?php echo array_get($file, 'fields:'.$field) ?></td>
              <?php endforeach ?>
            </tr>
          <?php endforeach ?>
        </tbody>
      </table>
    </div>
    <div class="take-action clearfix">
      <div class="input-status block-action pull-left" data-bind="css: {disabled: selectedFiles().length < 1}">
        <div class="input-select-wrap">
          <select data-bind="enable: selectedFiles().length > 0, selectedOptions: selectedAction" name="action">
            <option value=""><?php echo Localization::fetch('take_action')?></option>
            <option value="delete"><?php echo Localization::fetch('delete_files')?></option>
            <option value="ham"><?php echo Localization::fetch('mark_as_ham')?></option>
          </select>
        </div>
      </div>
      <input type="submit" class="btn pull-left" data-bind="visible: selectedAction() != '' && selectedFiles().length > 0" value="<?php echo Localization::fetch('confirm_action')?>">
    </div>
  </div>
</form>

<script type="text/javascript">
  var viewModel = {
      selectedFiles: ko.observableArray(),
      selectedAction: ko.observable(''),
  };

  viewModel.selectedFiles.subscribe(function(item){
    // console.log('selected ' + item);
  }, viewModel);

  viewModel.selectedAction.subscribe(function(action) {
    // console.log('selected ' + action);
  }, viewModel);

  ko.applyBindings(viewModel);

</script>
