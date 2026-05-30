<template>
  <div style="left:0px;top:0px;width:400px;height:500px" title="v-for List Test">
    <div style="left:0px;top:0px;width:400px;height:500px" class="main-bg"></div>

    <!-- Title -->
    <span style="left:14px;top:14px;font-size:18px;color:#FFFFFF;font-weight:bold">{{ listTitle }}</span>

    <!-- Scroll container with v-for list items -->
    <div style="overflow:auto;left:10px;top:46px;width:380px;height:400px" :scroll-top="scrollTop">
      <template v-for="item in todoItems" :key="item.id">
        <div class="item-bg"
             style="height:48px;margin-bottom:4px"
             @click="deleteItem(item.id)">
          <span style="left:14px;font-size:14px;color:#EAEAEA">{{ item.text }}</span>
        </div>
      </template>
    </div>

    <!-- Add button -->
    <button style="left:125px;top:458px;width:150px;height:32px" class="add-btn" @click="addItem">
      <span>{{ addBtnText }}</span>
    </button>
  </div>
</template>

<script lang="php">
class AppComponent extends ReactiveComponent
{
    public string $listTitle = "Todo List";
    public string $addBtnText = "Add Item";
    public string $scrollTop = "0";
    public array $todoItems = [
        ['id' => '1', 'text' => 'Task 1'],
        ['id' => '2', 'text' => 'Task 2'],
        ['id' => '3', 'text' => 'Task 3'],
    ];

    public function deleteItem(string $id): void
    {
        foreach ($this->todoItems as $i => $item) {
            if ($item['id'] === $id) {
                unset($this->todoItems[$i]);
                $this->todoItems = array_values($this->todoItems);
                break;
            }
        }
        $this->dirty = true;
    }

    public function addItem(): void
    {
        $newId = (string)(count($this->todoItems) + 1);
        $this->todoItems[] = ['id' => $newId, 'text' => 'Task #' . $newId];
        $this->dirty = true;
    }
}
</script>

<style>
.main-bg { background: #1A1A2E; }
.item-bg { background: #282840; color: #EAEAEA; }
.add-btn { background: #4A90D9; }
</style>
